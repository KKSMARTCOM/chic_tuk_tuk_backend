<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Un enfant ou un adulte transporté (spec 2026-10-14, §3.2). */
class SubscriptionEnrollmentPerson extends Model
{
    use HasUuid;

    protected $table = 'subscription_enrollment_people';

    protected $fillable = ['enrollment_id', 'position', 'full_name', 'age', 'school_class', 'health_notes', 'profession'];

    protected $casts = ['position' => 'integer', 'age' => 'integer'];

    public function enrollment()
    {
        return $this->belongsTo(SubscriptionEnrollment::class, 'enrollment_id');
    }
}
