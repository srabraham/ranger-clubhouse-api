<?php

namespace App\Http\Filters;

use App\Models\Person;
use App\Models\PersonEvent;
use App\Models\Role;

/*
 * THE COLUMNS LISTED NEED TO BE IN SYNC WITH app/Models/PersonEvent.php::$fillable
 */

class PersonEventFilter
{
    const KEY_FIELDS = [
        'person_id',
        'year'
    ];

    const ADMIN_FIELDS = [
        'lms_course_id',
        'may_request_stickers',
        'org_vehicle_insurance',
        'sandman_affidavit',
        'signed_motorpool_agreement',
        'signed_personal_vehicle_agreement',
    ];

    const TIMESHEET_FIELDS = [
        'timesheet_confirmed',
    ];

    const READONLY_FIELDS = [
        'timesheet_confirmed_at'
    ];

    const HQ_FIELDS = [
        'asset_authorized',
    ];

    //
    // FIELDS_SERIALIZE & FIELDS_DESERIALIZE elements are
    // 0: array of field names
    // 1: allow fields if the person is the authorized user
    // 2: which roles are allowed the field (if null, allow any)
    //

    const FIELDS_SERIALIZE = [
        [self::KEY_FIELDS],
        [self::ADMIN_FIELDS],
        [self::READONLY_FIELDS],
        [self::HQ_FIELDS],
        [self::TIMESHEET_FIELDS],
    ];
    const FIELDS_DESERIALIZE = [
        [self::ADMIN_FIELDS, false, [Role::ADMIN]],
        [self::READONLY_FIELDS, false, [Role::ADMIN]],
        [self::HQ_FIELDS, false, [Role::ADMIN, Role::MANAGE, Role::VC, Role::TRAINER, Role::MENTOR]],
        [self::TIMESHEET_FIELDS, true, [Role::ADMIN, Role::TIMESHEET_MANAGEMENT]]
    ];

    protected PersonEvent $record;

    public function __construct(PersonEvent $record)
    {
        $this->record = $record;
    }

    public function serialize(Person $authorizedUser = null): array
    {
        return $this->buildFields(self::FIELDS_SERIALIZE, $authorizedUser);
    }

    public function deserialize(Person $authorizedUser = null): array
    {
        return $this->buildFields(self::FIELDS_DESERIALIZE, $authorizedUser);
    }

    public function buildFields(array $fieldGroups, $authorizedUser): array
    {
        $fields = [];

        $isUser = $authorizedUser && $this->record->person_id == $authorizedUser->id;

        foreach ($fieldGroups as $group) {
            $roles = null;

            if (count($group) == 1) {
                $allow = true;
            } else {
                $allow = $isUser && $group[1];
                if (isset($group[2])) {
                    $roles = $group[2];
                }
            }

            if ($allow || ($authorizedUser && $roles && $authorizedUser->hasRole($roles))) {
                $fields = array_merge($fields, $group[0]);
            }
        }

        return $fields;
    }
}
