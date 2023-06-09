<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Clinic extends Authenticatable implements JWTSubject
{
    use HasFactory;
    protected $guarded = [];


    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function appointments() : HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function medical_reports() : HasMany
    {
        return $this->hasMany(Medical_report::class);
    }

    public function address() : BelongsTo
    {
        return $this->BelongsTo(Address::class,'address_id');
    }

    public function reports() : HasMany
    {
        return $this->HasMany(Report::class);
    }

    public function secretaries() : HasMany
    {
        return $this->hasMany(Secretary::class);
    }

    public function doctor_applies() : HasMany
    {
        return $this->hasMany(Doc_apply::class);
    }

    public function doctor_clinics() : HasMany
    {
        return $this->hasMany(Doc_clinic::class);
    }

    public function worked_times() : HasMany
    {
        return $this->hasMany(Worked_time::class);
    }

    public function getJWTIdentifier() {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims() {
        return [];
    }
}
