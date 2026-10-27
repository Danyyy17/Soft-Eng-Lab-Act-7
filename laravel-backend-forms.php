<?php
/*
 * Laravel server-side counterpart of clinic-forms-demo.html.
 * Each section below goes in the file named in its header comment.
 * Client-side checks improve usability; these rules are the authoritative validation.
 */

/* ===== routes/api.php ===== */
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{PatientController, AppointmentController, InventoryTransactionController, ReportController};

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/patients',               [PatientController::class, 'store'])->middleware('can:patients.create');
    Route::post('/appointments',           [AppointmentController::class, 'store'])->middleware('can:appointments.create');
    Route::post('/inventory-transactions', [InventoryTransactionController::class, 'store'])->middleware('can:inventory.manage');
    Route::get('/reports/appointments',    [ReportController::class, 'appointments'])->middleware('can:reports.view');
});


/* ===== app/Http/Requests/StorePatientRequest.php ===== */
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool { return true; } // RBAC handled by route middleware

    public function rules(): array
    {
        return [
            'last_name'    => ['required', 'string', 'min:2', 'max:50'],
            'first_name'   => ['required', 'string', 'min:2', 'max:50'],
            'birthdate'    => ['required', 'date', 'before_or_equal:today'],
            'sex'          => ['required', 'in:Female,Male'],
            'civil_status' => ['required', 'in:Single,Married,Widowed,Separated'],
            'email'        => ['nullable', 'email:rfc', 'max:100'],
            'mobile'       => ['required', 'regex:/^09\d{9}$/'],
            'address'      => ['required', 'string', 'min:10', 'max:200'],
            'is_philhealth_member' => ['boolean'],
            'philhealth_no'=> ['exclude_unless:is_philhealth_member,true', 'required', 'digits:12'],
            'valid_id'     => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'consent'      => ['accepted'],
            'confirm_new'  => ['boolean'], // true when staff chose "Register as new patient anyway"
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Use the format 09XXXXXXXXX (11 digits).',
            'consent.accepted' => 'Data privacy consent (RA 10173) is required.',
        ];
    }
}


/* ===== app/Http/Controllers/PatientController.php ===== */
namespace App\Http\Controllers;

use App\Http\Requests\StorePatientRequest;
use App\Models\{Patient, AuditLog};
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{
    public function store(StorePatientRequest $request)
    {
        $data = $request->validated();
        $full = strtolower(trim($data['first_name'] . ' ' . $data['last_name']));

        // Fuzzy duplicate check: same birthdate and similar normalized name
        $dup = Patient::where('birthdate', $data['birthdate'])->get()->first(function ($p) use ($full) {
            $other = strtolower(trim($p->first_name . ' ' . $p->last_name));
            $dist  = levenshtein($full, $other);
            return (1 - $dist / max(strlen($full), strlen($other), 1)) >= 0.8;
        });

        if ($dup && ! ($data['confirm_new'] ?? false)) {
            return response()->json(['message' => 'Possible duplicate patient.', 'duplicate_id' => $dup->id], 409);
        }

        $patient = DB::transaction(function () use ($request, $data) {
            if ($request->hasFile('valid_id')) {
                $data['valid_id_path'] = $request->file('valid_id')->store('patient-ids', 'private');
            }
            $data['created_by'] = $request->user()->id;
            $patient = Patient::create($data);
            AuditLog::record($request->user(), 'patient.created', $patient);
            return $patient;
        });

        return response()->json($patient, 201);
    }
}


/* ===== app/Http/Requests/StoreAppointmentRequest.php ===== */
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use App\Models\Appointment;
use Carbon\Carbon;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'patient_id'   => ['required', 'exists:patients,id'],
            'provider_id'  => ['required', 'exists:healthcare_providers,id'],
            'procedure_id' => ['required', 'exists:procedures,id'],
            'room_id'      => ['required', 'exists:rooms,id'],
            'date'         => ['required', 'date', 'after_or_equal:today'],
            'time'         => ['required', 'date_format:H:i', 'after_or_equal:08:00', 'before_or_equal:17:00'],
            'duration'     => ['required', 'integer', 'between:15,180'],
            'equipment'    => ['array'],
            'equipment.*'  => ['exists:equipment,id'],
            'send_notice'  => ['boolean'],
            'notes'        => ['nullable', 'string', 'max:250'],
            'status'       => ['in:Scheduled'],
        ];
    }

    // Interval-overlap conflict detection: newStart < existingEnd AND existingStart < newEnd
    public function after(): array
    {
        return [function (Validator $v) {
            if ($v->errors()->isNotEmpty()) return;
            $start = Carbon::parse($this->date . ' ' . $this->time);
            $end   = $start->copy()->addMinutes((int) $this->duration);

            $conflict = Appointment::where('status', 'Scheduled')
                ->where('start_at', '<', $end)
                ->where('end_at', '>', $start)
                ->where(fn ($q) => $q->where('provider_id', $this->provider_id)->orWhere('room_id', $this->room_id))
                ->exists();

            if ($conflict) {
                $v->errors()->add('time', 'The provider or room is already booked for this time.');
            }
        }];
    }
}


/* ===== app/Http/Requests/StoreInventoryTransactionRequest.php ===== */
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\InventoryItem;

class StoreInventoryTransactionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $in = $this->type === 'Stock in';
        return [
            'item_id'        => ['required', 'exists:inventory_items,id'],
            'type'           => ['required', 'in:Stock in,Stock out'],
            'quantity'       => ['required', 'integer', 'between:1,5000', function ($attr, $value, $fail) {
                $item = InventoryItem::find($this->item_id);
                if ($this->type === 'Stock out' && $item && $value > $item->quantity) {
                    $fail('Stock out cannot exceed the current balance.');
                }
            }],
            'unit'           => ['required', 'in:pcs,box,vial,bottle'],
            'supplier'       => [$in ? 'required' : 'nullable', 'string', 'max:100'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'expiry_date'    => ['nullable', 'date', 'after:transaction_date'],
            'unit_cost'      => [$in ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'reorder_point'  => ['required', 'integer', 'between:5,100'],
            'urgent'         => ['boolean'],
            'receipt'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'remarks'        => ['nullable', 'string', 'max:150'],
        ];
    }
}


/* ===== app/Http/Requests/ReportFilterRequest.php ===== */
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'keyword'     => ['nullable', 'string', 'max:50'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
            'provider_id' => ['nullable', 'exists:healthcare_providers,id'],
            'status'      => ['required', 'array', 'min:1'],
            'status.*'    => ['in:Scheduled,Completed,Cancelled'],
            'sort'        => ['in:asc,desc'],
            'per_page'    => ['integer', 'between:5,100'],
            'archived'    => ['boolean'],
            'format'      => ['in:csv,json'],
        ];
    }
}
