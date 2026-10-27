<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $t) {
            $t->id();
            $t->string('last_name', 50);
            $t->string('first_name', 50);
            $t->date('birthdate');
            $t->enum('sex', ['Female', 'Male']);
            $t->enum('civil_status', ['Single', 'Married', 'Widowed', 'Separated']);
            $t->string('email', 100)->nullable();
            $t->string('mobile', 11);
            $t->string('address', 200);
            $t->boolean('is_philhealth_member')->default(false);
            $t->string('philhealth_no', 12)->nullable();
            $t->string('valid_id_path')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['birthdate']);
        });

        Schema::create('healthcare_providers', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('specialty'); $t->timestamps();
        });
        Schema::create('procedures', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->unsignedSmallInteger('default_minutes'); $t->timestamps();
        });
        Schema::create('rooms', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->timestamps();
        });
        Schema::create('equipment', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->timestamps();
        });

        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('provider_id')->constrained('healthcare_providers');
            $t->foreignId('procedure_id')->constrained();
            $t->foreignId('room_id')->constrained();
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->unsignedSmallInteger('duration');
            $t->boolean('send_notice')->default(true);
            $t->string('notes', 250)->nullable();
            $t->enum('status', ['Scheduled', 'Completed', 'Cancelled'])->default('Scheduled');
            $t->boolean('archived')->default(false);
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['provider_id', 'start_at', 'end_at']);
            $t->index(['room_id', 'start_at', 'end_at']);
        });

        Schema::create('appointment_equipment', function (Blueprint $t) {
            $t->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('equipment_id')->constrained('equipment');
            $t->primary(['appointment_id', 'equipment_id']);
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id(); $t->string('name')->unique();
            $t->integer('quantity')->default(0);
            $t->unsignedSmallInteger('reorder_point')->default(20);
            $t->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('inventory_items');
            $t->enum('type', ['Stock in', 'Stock out']);
            $t->unsignedInteger('quantity');
            $t->string('unit', 10);
            $t->string('supplier', 100)->nullable();
            $t->date('transaction_date');
            $t->date('expiry_date')->nullable();
            $t->decimal('unit_cost', 10, 2)->nullable();
            $t->boolean('urgent')->default(false);
            $t->string('receipt_path')->nullable();
            $t->string('remarks', 150)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->string('action');
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'inventory_transactions', 'inventory_items', 'appointment_equipment',
                  'appointments', 'equipment', 'rooms', 'procedures', 'healthcare_providers', 'patients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    protected $fillable = ['last_name','first_name','birthdate','sex','civil_status','email','mobile',
        'address','is_philhealth_member','philhealth_no','valid_id_path','created_by'];
    protected $casts = ['birthdate' => 'date:Y-m-d', 'is_philhealth_member' => 'boolean'];
}

class HealthcareProvider extends Model { protected $fillable = ['name','specialty']; }
class Procedure extends Model { protected $fillable = ['name','default_minutes']; }
class Room extends Model { protected $fillable = ['name']; }
class Equipment extends Model { protected $table = 'equipment'; protected $fillable = ['name']; }

class Appointment extends Model
{
    protected $fillable = ['patient_id','provider_id','procedure_id','room_id','start_at','end_at',
        'duration','send_notice','notes','status','archived','created_by'];
    protected $casts = ['start_at' => 'datetime', 'end_at' => 'datetime',
        'send_notice' => 'boolean', 'archived' => 'boolean'];

    public function patient()   { return $this->belongsTo(Patient::class); }
    public function provider()  { return $this->belongsTo(HealthcareProvider::class, 'provider_id'); }
    public function procedure() { return $this->belongsTo(Procedure::class); }
    public function room()      { return $this->belongsTo(Room::class); }
    public function equipment() { return $this->belongsToMany(Equipment::class, 'appointment_equipment'); }
}

class InventoryItem extends Model { protected $fillable = ['name','quantity','reorder_point']; }

class InventoryTransaction extends Model
{
    protected $fillable = ['item_id','type','quantity','unit','supplier','transaction_date','expiry_date',
        'unit_cost','urgent','receipt_path','remarks','created_by'];
}

class AuditLog extends Model
{
    protected $fillable = ['user_id','action','subject_type','subject_id'];

    public static function record($user, string $action, Model $subject): self
    {
        return static::create([
            'user_id' => $user->id, 'action' => $action,
            'subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(),
        ]);
    }
}


namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\{HealthcareProvider, Procedure, Room, Equipment, InventoryItem};

class ClinicSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['Dr. Reyes','Ophthalmology'],['Dr. Santos','Orthopedics'],['Dr. Lim','Gastroenterology'],['Dr. Cruz','OB-GYN']] as [$n,$s])
            HealthcareProvider::create(['name' => $n, 'specialty' => $s]);

        foreach ([['Cataract surgery',90],['Eye consultation',30],['Colonoscopy',60],['Knee arthroscopy',120]] as [$n,$m])
            Procedure::create(['name' => $n, 'default_minutes' => $m]);

        foreach (['Consultation Room 1','Operating Room 1','Procedure Room 2'] as $n) Room::create(['name' => $n]);
        foreach (['Slit lamp','Phaco machine','Endoscope','Arthroscopy set'] as $n) Equipment::create(['name' => $n]);

        foreach ([['Intraocular lens',25,20],['Surgical gloves (box)',60,30],['Sterile drape',18,25],['Povidone iodine',40,15]] as [$n,$q,$r])
            InventoryItem::create(['name' => $n, 'quantity' => $q, 'reorder_point' => $r]);
    }
}



namespace App\Http\Controllers;

use App\Http\Requests\StoreAppointmentRequest;
use App\Models\{Appointment, AuditLog};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentController extends Controller
{
    public function store(StoreAppointmentRequest $request)
    {
        $d = $request->validated();

        $appointment = DB::transaction(function () use ($request, $d) {
            $start = Carbon::parse($d['date'] . ' ' . $d['time']);

            $appt = Appointment::create([
                'patient_id'   => $d['patient_id'],
                'provider_id'  => $d['provider_id'],
                'procedure_id' => $d['procedure_id'],
                'room_id'      => $d['room_id'],
                'start_at'     => $start,
                'end_at'       => $start->copy()->addMinutes((int) $d['duration']),
                'duration'     => $d['duration'],
                'send_notice'  => $d['send_notice'] ?? false,
                'notes'        => $d['notes'] ?? null,
                'status'       => 'Scheduled',
                'created_by'   => $request->user()->id,
            ]);
            $appt->equipment()->sync($d['equipment'] ?? []);
            AuditLog::record($request->user(), 'appointment.created', $appt);
            return $appt;
        });

        return response()->json($appointment->load(['patient','provider','procedure','room','equipment']), 201);
    }
}



namespace App\Http\Controllers;

use App\Http\Requests\StoreInventoryTransactionRequest;
use App\Models\{InventoryItem, InventoryTransaction, AuditLog};
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class InventoryTransactionController extends Controller
{
    public function store(StoreInventoryTransactionRequest $request)
    {
        $d = $request->validated();

        $result = DB::transaction(function () use ($request, $d) {
           
            $item = InventoryItem::lockForUpdate()->findOrFail($d['item_id']);

            if ($d['type'] === 'Stock out' && $d['quantity'] > $item->quantity) {
                abort(422, 'Stock out cannot exceed the current balance.');
            }

            $item->quantity += $d['type'] === 'Stock in' ? $d['quantity'] : -$d['quantity'];
            $item->reorder_point = $d['reorder_point'];
            $item->save();

            if ($request->hasFile('receipt')) {
                $d['receipt_path'] = $request->file('receipt')->store('receipts', 'private');
            }
            $d['created_by'] = $request->user()->id;

            $tx = InventoryTransaction::create(Arr::except($d, ['reorder_point', 'receipt']));
            AuditLog::record($request->user(), 'inventory.' . strtolower(str_replace(' ', '_', $d['type'])), $tx);

            return ['transaction' => $tx, 'item' => $item, 'low_stock' => $item->quantity <= $item->reorder_point];
        });

        return response()->json($result, 201);
    }
}



namespace App\Http\Controllers;

use App\Http\Requests\ReportFilterRequest;
use App\Models\Appointment;

class ReportController extends Controller
{
    public function appointments(ReportFilterRequest $request)
    {
        $f = $request->validated();

        $q = Appointment::with(['patient','provider','procedure','room'])
            ->whereIn('status', $f['status'])
            ->where('archived', $f['archived'] ?? false)
            ->when($f['provider_id'] ?? null, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('start_at', '>=', $d))
            ->when($f['to'] ?? null,   fn ($q, $d) => $q->whereDate('start_at', '<=', $d))
            ->when($f['keyword'] ?? null, function ($q, $kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($w) => $w
                    ->whereHas('patient', fn ($p) => $p->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like))
                    ->orWhereHas('procedure', fn ($p) => $p->where('name', 'like', $like)));
            })
            ->orderBy('start_at', $f['sort'] ?? 'asc');

        if (($f['format'] ?? 'json') === 'csv') {
            $rows = $q->get();
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['id','patient','provider','procedure','date','time','status']);
                foreach ($rows as $a) {
                    fputcsv($out, [$a->id, $a->patient->first_name . ' ' . $a->patient->last_name,
                        $a->provider->name, $a->procedure->name,
                        $a->start_at->format('Y-m-d'), $a->start_at->format('H:i'), $a->status]);
                }
                fclose($out);
            }, 'report.csv', ['Content-Type' => 'text/csv']);
        }

        return response()->json($q->paginate($f['per_page'] ?? 10));
    }
}
