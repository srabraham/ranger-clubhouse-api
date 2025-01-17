<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Award extends ApiModel
{
    protected $table = 'award';
    protected $auditModel = true;
    public $timestamps = true;

    // Year round contribution (cadre membership, extraordinary work, etc.)
    const TYPE_YEAR_ROUND = 'year-round';

    // Special event award such as Operation Non Event
    const TYPE_SPECIAL_EVENT = 'special-event';

    // Playa service
    const TYPE_PLAYA_SERVICE = 'playa-service';

    protected $rules = [
        'description' => 'required|string',
        'icon' => 'required|string',
        'title' => 'required|string',
        'type' => 'required|string',
    ];

    protected $fillable = [
        'description',
        'icon',
        'title',
        'type',
    ];

    public function person_award(): HasMany
    {
        return $this->hasMany(PersonAward::class);
    }

    public static function findAll()
    {
        return self::get()->sortBy('title', SORT_NATURAL)->values();
    }
}
