<?php

namespace App\Lib;

use App\Models\Document;
use App\Models\EventDate;
use App\Models\Person;
use App\Models\PersonEvent;
use App\Models\PersonOnlineTraining;
use App\Models\PersonPosition;
use App\Models\PersonRole;
use App\Models\Position;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Slot;
use App\Models\Survey;
use App\Models\Timesheet;
use App\Models\TraineeStatus;
use App\Models\TrainerStatus;
use App\Models\Training;
use App\Models\Vehicle;
use Carbon\Carbon;

class Milestones
{
    /**
     * Build up the various milestones completed or pending for a given person.
     * Use heavily by the dashboards.
     *
     * @param Person $person
     * @return array
     */

    public static function buildForPerson(Person $person): array
    {
        $status = $person->status;

        $now = now();
        $year = $now->year;

        $event = PersonEvent::firstOrNewForPersonYear($person->id, $year);
        $period = EventDate::calculatePeriod();
        $isBinary = Timesheet::isPersonBinary($person);
        $isNonRanger = ($status == Person::NON_RANGER);

        $settings = setting([
            'MotorpoolPolicyEnable',
            'OnboardAlphaShiftPrepLink',
            'OnlineTrainingEnabled',
            'OnlineTrainingUrl',
            'RadioCheckoutAgreementEnabled',
        ]);

        $milestones = [
            'online_training_passed' => $isNonRanger || PersonOnlineTraining::didCompleteForYear($person->id, $year),
            'online_training_enabled' => $settings['OnlineTrainingEnabled'],
            'online_training_url' => $settings['OnlineTrainingUrl'],
            'behavioral_agreement' => $person->behavioral_agreement,
            'has_reviewed_pi' => !empty($event->pii_finished_at) && $event->pii_finished_at->year == $year,
            'asset_authorized' => $event->asset_authorized,
            'radio_checkout_agreement_enabled' => $settings['RadioCheckoutAgreementEnabled'],
            'trainings_available' => Slot::haveActiveForPosition(Position::TRAINING),
            'surveys' => Survey::retrieveUnansweredForPersonYear($person->id, $year),
            'period' => $period,
        ];

        if ($status != Person::AUDITOR && empty($person->bpguid)) {
            $milestones['missing_bpguid'] = true;
        }

        $trainings = PersonPosition::findTrainingPositions($person->id, true);
        $artTrainings = [];
        foreach ($trainings as $training) {
            $education = Training::retrieveEducation($person->id, $training, $year);
            if ($training->id == Position::TRAINING) {
                $milestones['training'] = $education;
            } else {
                $artTrainings[] = $education;
            }
        }

        if (!isset($milestones['training'])) {
            $milestones['training'] = ['status' => 'no-shift'];
        }

        usort($artTrainings, fn($a, $b) => strcasecmp($a->position_title, $b->position_title));

        $milestones['art_trainings'] = $artTrainings;


        if (in_array($status, Person::ACTIVE_STATUSES)) {
            // Only require Online Training to be passed in order to work? (2021 social distancing training)
            $milestones['online_training_only'] = setting($isBinary ? 'OnlineTrainingOnlyForBinaries' : 'OnlineTrainingOnlyForVets');
        }

        switch ($status) {
            case Person::AUDITOR:
                if (setting('OnlineTrainingOnlyForAuditors')) {
                    $milestones['online_training_only'] = true;
                }
                break;

            case Person::ALPHA:
            case Person::PROSPECTIVE:
            case Person::BONKED:
                $milestones['alpha_shift_prep_link'] = $settings['OnboardAlphaShiftPrepLink'];
                $milestones['alpha_shifts_available'] = $haveAlphaShifts = Slot::haveActiveForPosition(Position::ALPHA);
                if ($haveAlphaShifts) {
                    $alphaShift = Schedule::findEnrolledSlots($person->id, $year, Position::ALPHA)->last();
                    if ($alphaShift) {
                        $milestones['alpha_shift'] = [
                            'slot_id' => $alphaShift->id,
                            'begins' => (string)$alphaShift->begins,
                            'status' => Carbon::parse($alphaShift->begins)->addHours(24)->lte($now) ? Training::NO_SHOW : Training::PENDING,
                        ];
                    }
                }
                $milestones['needs_full_training'] = true;
                break;

            case Person::ACTIVE:
                if ($isBinary) {
                    // Binaries have to take a full day's training, or
                    // everyone is being forced to take the full version.
                    $milestones['needs_full_training'] = true;
                    $milestones['is_binary'] = $isBinary;
                }

                if (setting('OnlineTrainingFullCourseForVets')) {
                    $milestones['needs_full_online_course'] = true;
                }
                break;

            case Person::INACTIVE:
                // Inactives need a full day's training
                $milestones['needs_full_training'] = true;
                break;

            case Person::INACTIVE_EXTENSION:
            case Person::RETIRED:
            case Person::RESIGNED:
                // Full day's training required, and walk a cheetah shift.
                $milestones['needs_full_training'] = true;
                $milestones['is_cheetah_cub'] = true;
                $cheetah = Schedule::findEnrolledSlots($person->id, $year, Position::CHEETAH_CUB)->last();
                if ($cheetah) {
                    $milestones['cheetah_cub_shift'] = [
                        'slot_id' => $cheetah->id,
                        'begins' => (string)$cheetah->begins,
                    ];
                }
                break;
        }


        if (in_array($status, Person::ACTIVE_STATUSES) || $isNonRanger) {
            // Starting late 2022 - All (effective) login management roles require annual NDA signature.
            // MOAR PAPERWERKS! MOAR WINZ!
            // Don't require the NDA if the agreement does not exist.
            if (PersonRole::haveRole($person->id, Role::MANAGE)
                && !$event->signed_nda
                && Document::haveTag(Agreements::DEPT_NDA)
            ) {
                $milestones['nda_required'] = true;
            }

            if (!$isNonRanger) {
                // note, some inactives are active trainers yet do not work on playa.
                $milestones['is_trainer'] = PersonPosition::havePosition($person->id, Position::TRAINERS[Position::TRAINING]);
            }
            $ticketingPeriod = setting('TicketingPeriod');
            $milestones['ticketing_period'] = $ticketingPeriod;
            if ($ticketingPeriod == 'open' || $ticketingPeriod == 'closed') {
                $milestones['ticketing_package'] = TicketAndProvisionsPackage::buildPackageForPerson($person->id);
            }

            // Timesheets!
            if (setting('TimesheetCorrectionEnable')) {
                $didWork = $milestones['did_work'] = Timesheet::didPersonWork($person->id, $year);
                if ($didWork) {
                    $milestones['timesheets_unverified'] = Timesheet::countUnverifiedForPersonYear($person->id, $year);
                    $milestones['timesheet_confirmed'] = $event->timesheet_confirmed;
                }
            }

            if ($period != EventDate::AFTER_EVENT) {
                if (!$isNonRanger) {
                    $milestones['dirt_shifts_available'] = Schedule::areDirtShiftsAvailable();
                }
                $milestones['shift_signups'] = Schedule::summarizeShiftSignups($person);
                // Person is *not* signed up - figure out if weekend shirts are available
                $milestones ['burn_weekend_available'] = Schedule::haveAvailableBurnWeekendShiftsForPerson($person);
                // Burn weekend!
                $milestones['burn_weekend_signup'] = Schedule::haveBurnWeekendSignup($person);

                $milestones['motorpool_agreement_available'] = $settings['MotorpoolPolicyEnable'];
                $milestones['motorpool_agreement_signed'] = $event->signed_motorpool_agreement;

                if ($event->may_request_stickers) {
                    $milestones['vehicle_requests_allowed'] = true;
                    $milestones['vehicle_requests'] = Vehicle::findForPersonYear($person->id, $year);
                }

                if (!$isNonRanger && PersonPosition::havePosition($person->id, Position::SANDMAN)) {
                    if ($event->sandman_affidavit) {
                        $milestones['sandman_affidavit_signed'] = true;
                    } else if (TraineeStatus::didPersonPassForYear($person->id, Position::SANDMAN_TRAINING, $year)
                        || TrainerStatus::didPersonTeachForYear($person->id, Position::SANDMAN_TRAINING, $year)) {
                        // Sandpeople! Put the affidavit in your walk, head-to-toe let your whole body talk.
                        $milestones['sandman_affidavit_unsigned'] = true;
                    }
                }
            }
        }

        return $milestones;
    }
}
