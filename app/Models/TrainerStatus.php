<?php

namespace App\Models;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrainerStatus extends ApiModel
{
    protected $table = 'trainer_status';
    protected $auditModel = true;
    public $timestamps = true;

    const ATTENDED = 'attended';
    const PENDING = 'pending';
    const NO_SHOW = 'no-show';

    protected $guarded = [];

    public function slot()
    {
        return $this->belongsTo(Slot::class);
    }

    public function trainer_slot()
    {
        return $this->belongsTo(Slot::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * trainer_slot_id refers to the Trainer sign up (Trainer / Trainer Associate / Trainer Uber /etc)
     * slot_id refers to the training session (trainee)
     *
     * @param $sessionId
     * @param $personId
     * @return ?TrainerStatus
     */

    public static function firstOrNewForSession($sessionId, $personId)
    {
        return self::firstOrNew(['person_id' => $personId, 'slot_id' => $sessionId]);
    }

    public static function findBySlotPersonIds($slotId, $personIds)
    {
        return self::where('slot_id', $slotId)->whereIntegerInRaw('person_id', $personIds)->get();
    }

    /**
     * Did a person teach a session?
     *
     * @param int $personId the person to query
     * @param int $positionId the position (Training / Green Dot Training / etc) to see if they taught
     * @param int $year the year to check
     * @return bool return true if the person taught
     */

    public static function didPersonTeachForYear(int $personId, int $positionId, int $year): bool
    {
        $positionIds = Position::TRAINERS[$positionId] ?? null;
        if (!$positionIds) {
            return false;
        }

        return DB::table('slot')
            ->join('trainer_status', 'slot.id', 'trainer_status.trainer_slot_id')
            ->whereYear('slot.begins', $year)
            ->whereIn('slot.position_id', $positionIds)
            ->where('trainer_status.person_id', $personId)
            ->where('trainer_status.status', self::ATTENDED)
            ->where('slot.active', true)
            ->exists();
    }

    /**
     * Retrieve all the sessions the person may have taught
     *
     * @param int $personId the person to check
     * @param array $positionIds the positions to check (Trainer / Trainer Assoc. / Uber /etc)
     * @param int $year the year to check
     * @return Collection
     */

    public static function retrieveSessionsForPerson(int $personId, $positionIds, int $year)
    {
        return DB::table('slot')
            ->select('slot.id', 'slot.begins', 'slot.ends', 'slot.description', 'slot.position_id', DB::raw('IFNULL(trainer_status.status, "pending") as status'))
            ->join('person_slot', function ($q) use ($personId) {
                $q->on('person_slot.slot_id', 'slot.id');
                $q->where('person_slot.person_id', $personId);
            })
            ->leftJoin('trainer_status', function ($q) use ($personId) {
                $q->on('trainer_status.trainer_slot_id', 'slot.id');
                $q->where('trainer_status.person_id', $personId);
            })
            ->where('slot.active', true)
            ->whereYear('slot.begins', $year)
            ->whereIn('position_id', $positionIds)
            ->orderBy('slot.begins')
            ->get();
    }

    /*
     * Delete all records referring to a slot. Used by slot deletion.
     */

    public static function deleteForSlot($slotId)
    {
        self::where('slot_id', $slotId)->delete();
        self::where('trainer_slot_id', $slotId)->delete();
    }
}
