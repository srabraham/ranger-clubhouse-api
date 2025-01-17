<?php

namespace App\Models;

class PersonEventInfo extends ApihouseResult
{
    public $person_id;
    public $year;

    public $trainings;
    public $radio_eligible;
    public $radio_max;
    public $radio_banked;
    public $meals;
    public $showers;
    public $radio_info_available;

    public $online_training_passed;
    public $online_training_date;

    public $vehicles;

    public $is_binary;
    public $online_training_only;

    /*
     * Gather all information related to a given year for a person
     * - training status & location (if any)
     * - radio eligibility
     * - meals & shower privileges
     * @var $personId - person to lookup
     * @var $year -
     * @return PersonEventInfo
     */

    public static function findForPersonYear(Person $person, $year)
    {
        $personId = $person->id;
        $info = new PersonEventInfo();
        $isCurrentYear = (current_year() == $year);

        $info->person_id = $personId;
        $info->year = $year;

        $requireTraining = PersonPosition::findTrainingPositions($personId);
        $info->trainings = [];

        if ($requireTraining->isEmpty()) {
            $requireTraining = [Position::find(Position::TRAINING)];
        }

        foreach ($requireTraining as $position) {
            $info->trainings[] = Training::retrieveEducation($personId, $position, $year);
        }

        usort($info->trainings, fn($a, $b) => strcasecmp($a->position_title, $b->position_title));

        $info->radio_info_available = setting('RadioInfoAvailable');

        if ($info->radio_info_available) {
            $radios = Provision::where('type', Provision::EVENT_RADIO)
                        ->where('person_id', $personId)
                        ->whereIn('status', [ Provision::AVAILABLE, Provision::CLAIMED, Provision::SUBMITTED])
                        ->get();
            if ($radios->isNotEmpty()) {
                $info->radio_eligible = true;
                $info->radio_max = $radios->max('item_count');
                $info->radio_banked = false;
            } else {
                $info->radio_eligible = false;
                $info->radio_max = 0;
                $info->radio_banked = Provision::where('type', Provision::EVENT_RADIO)
                    ->where('person_id', $personId)
                    ->where('status', Provision::BANKED)
                    ->exists();
            }
        }

        $bmid = Bmid::findForPersonManage($personId, $year);

        if ($bmid) {
            $info->meals = $bmid->effectiveMeals();
            $info->showers = $bmid->showers || $bmid->earned_showers || $bmid->allocated_showers;
         } else {
            $info->meals = '';
            $info->showers = false;
        }

        $ot = PersonOnlineTraining::findForPersonYear($personId, $year);

        if (in_array($person->status, Person::ACTIVE_STATUSES)) {
            $info->is_binary = Timesheet::isPersonBinary($person);
            $info->online_training_only = setting($info->is_binary ? 'OnlineTrainingOnlyForBinaries' : 'OnlineTrainingOnlyForVets');
        }

        if ($ot) {
            $info->online_training_passed = true;
            $info->online_training_date = (string)$ot->completed_at;
        } else {
            $info->online_training_passed = false;
        }

        $info->vehicles = Vehicle::findForPersonYear($personId, $year);
        $event = PersonEvent::findForPersonYear($personId, $year);

        if ($event) {
            $info->may_request_stickers = $event->may_request_stickers;
            $info->org_vehicle_insurance = $event->org_vehicle_insurance;
            $info->signed_motorpool_agreement = $event->signed_motorpool_agreement;
        } else {
            $info->may_request_stickers = false;
            $info->org_vehicle_insurance = false;
            $info->signed_motorpool_agreement = false;
        }
        return $info;
    }
}
