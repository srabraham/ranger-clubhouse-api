<?php

namespace App\Lib\Reports;

use App\Models\PersonSlot;
use App\Models\Slot;

class ScheduleByCallsignReport
{
    /*
     * Schedule By Callsign report
     */

    public static function execute(int $year): array
    {
        $slots = Slot::select('id', 'begins', 'ends', 'description', 'position_id')
            ->whereYear('slot.begins', $year)
            ->with('position:id,title,active')
            ->orderBy('begins')
            ->get();

        if ($slots->isEmpty()) {
            return [
                'people' => [],
                'positions' => [],
                'slots' => [],
            ];
        }

        $positions = [];
        foreach ($slots as $slot) {
            if (isset($positions[$slot->position_id])) {
                continue;
            }

            $positions[$slot->position_id] = [
                'id' => $slot->position_id,
                'title' => $slot->position->title,
                'active' => $slot->position->active
            ];
        }


        $signups = PersonSlot::whereIntegerInRaw('slot_id', $slots->pluck('id'))
            ->with('person:id,callsign,status')
            ->get();

        $people = [];

        foreach ($signups as $signup) {
            $person = $signup->person;
            if (!isset($people[$signup->person_id])) {
                $people[$signup->person_id] = [
                    'id' => $person->id,
                    'callsign' => $person->callsign,
                    'status' => $person->status,
                    'slot_ids' => []
                ];
            }
            $people[$signup->person_id]['slot_ids'][] = $signup->slot_id;
        }

        $people = array_values($people);
        usort($people, fn($a, $b) => strcasecmp($a['callsign'], $b['callsign']));
        return [
            'people' => $people,
            'positions' => $positions,
            'slots' => $slots->keyBy('id')->toArray(),
        ];
    }
}