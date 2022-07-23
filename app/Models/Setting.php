<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use RuntimeException;

class Setting extends ApiModel
{
    protected $table = 'setting';
    public $timestamps = true;

    // Allow all fields to be filled.
    protected $guarded = [];

    protected $primaryKey = 'name';
    public $incrementing = false;

    protected $auditModel = true;

    protected $rules = [
        'name' => 'required|string',
        'value' => 'string|nullable'
    ];

    public $appends = [
        'type',
        'description',
        'is_credential',
        'options'
    ];

    public static $cache = [];

    const TYPE_BOOL = 'bool';
    const TYPE_DATE = 'date';
    const TYPE_DATETIME = 'datetime';
    const TYPE_EMAIL = 'email';
    const TYPE_FLOAT = 'float';
    const TYPE_INTEGER = 'integer';
    const TYPE_STRING = 'string';
    const TYPE_URL = 'url';

    /*
     * Each setting must be described in the table below.
     *
     * The definitions are:
     * description: single line detail on what the setting is
     * type: the value type (bool,string,json,email,date,datetime,integer,float)
     * is_credential (optional) - set true if the setting is a credential and should not be included in a redacted
     *         database dump, the value returned in an API lookup response, or prevented from being set by a non-Tech Ninja
     * options: array of possible options format is [ 'option', 'description' ]
     */

    const DESCRIPTIONS = [
        'AccountCreationEmail' => [
            'description' => 'Alert email address when accounts register',
            'type' => self::TYPE_EMAIL,
        ],

        'AdminEmail' => [
            'description' => 'Ranger Tech Team Email Address',
            'type' => self::TYPE_EMAIL,
        ],

        'AllowSignupsWithoutPhoto' => [
            'description' => 'Allow shift signups without requiring an approved photo',
            'type' => self::TYPE_BOOL,
        ],


        'AllYouCanEatEventWeekThreshold' => [
            'description' => 'Event week hour threshold to earn an All-You-Can-Eat-Pass',
            'type' => self::TYPE_INTEGER
        ],

        'AllYouCanEatEventPeriodThreshold' => [
            'description' => 'Event period (pre-event,event,post-event weeks) hour threshold to earn an All-You-Can-Eat-Pass',
            'type' => self::TYPE_INTEGER
        ],

        'AuditorRegistrationDisabled' => [
            'description' => 'Prevent Auditors from registering for an account in the Clubhouse',
            'type' => self::TYPE_BOOL,
            'default' => false,
        ],

        'BroadcastClubhouseNotify' => [
            'description' => 'Enable RBS notification of new Clubhouse messages',
            'type' => self::TYPE_BOOL,
        ],

        'BroadcastClubhouseSandbox' => [
            'description' => 'Enable RBS Clubhouse Message sandbox mode (Clubhouse messages not created)',
            'type' => self::TYPE_BOOL,
        ],

        'BroadcastMailSandbox' => [
            'description' => 'Enable RBS sandbox email mode',
            'type' => self::TYPE_BOOL,
        ],

        'BroadcastSMSSandbox' => [
            'description' => 'Sandbox SMS messages',
            'type' => self::TYPE_BOOL,
        ],

        'DailyReportEmail' => [
            'description' => 'Email address to send the Clubhouse Daily Report',
            'type' => self::TYPE_EMAIL,
        ],

        'DashboardPeriod' => [
            'description' => 'The Dashboard Period',
            'type' => self::TYPE_STRING,
            'default' => 'auto',
            'options' => [
                ['auto', 'Period automatically determined'],
                ['after-event', 'After Event (Sept thru March)'],
                ['before-event', 'Before Event (March thru mid-to-late August)'],
                ['event', 'Event Period (mid Aug til 1st Sat after Labor Day)'],
            ]
        ],

        'DoNotReplyEmail' => [
            'description' => 'Most generated Clubhouse emails have a reply to of do-not-reply@XXX.org',
            'type' => self::TYPE_EMAIL,
            'default' => 'do-not-reply@burningmail.burningman.org'
        ],

        'EditorUrl' => [
            'description' => 'The script URL of the WYSIWYG editor (currently TinyMCE)',
            'type' => self::TYPE_URL
        ],

        'HQWindowInterfaceEnabled' => [
            'description' => 'Enable the HQ Window Interface (normally enabled during the event)',
            'type' => self::TYPE_BOOL,
            'default' => true,
        ],

        'GeneralSupportEmail' => [
            'description' => 'General Ranger Email Address',
            'type' => self::TYPE_EMAIL,
        ],

        'JoiningRangerSpecialTeamsUrl' => [
            'description' => 'How To Join Ranger Special Teams Document URL',
            'type' => self::TYPE_STRING,
        ],

        'MailingListUpdateRequestEmail' => [
            'description' => 'Email address(es) to send a message when an active Ranger requests to update the mailing list subscriptions',
            'type' => self::TYPE_EMAIL,
        ],

        'MealDates' => [
            'description' => 'Commissary dates and hours',
            'type' => self::TYPE_STRING,
        ],

        'MealInfoAvailable' => [
            'description' => 'True if meal information is available.',
            'type' => self::TYPE_BOOL,
        ],

        'MentorEmail' => [
            'description' => 'Mentor Cadre email. Shown to Alphas when suggesting contacting the cadre.',
            'type' => self::TYPE_EMAIL
        ],

        'MotorpoolPolicyEnable' => [
            'description' => 'Enable Motorpool Policy Page',
            'type' => self::TYPE_BOOL,
        ],

        'OnboardAlphaShiftPrepLink' => [
            'description' => 'Used by the Onboarding Checklist for PNVs. Link to how to prep for your Alpha Shift in the Ranger Manual',
            'type' => self::TYPE_STRING
        ],

        'OnlineTrainingEnabled' => [
            'description' => 'Enable online course link',
            'type' => self::TYPE_BOOL
        ],

        'OnlineTrainingUrl' => [
            'description' => 'Online course Url',
            'type' => self::TYPE_STRING
        ],

        'OnlineTrainingDisabledAllowSignups' => [
            'description' => 'Enable shift signups even if the Online Course is disabled (VERY DANGEROUS)',
            'type' => self::TYPE_BOOL,
            'default' => false,
        ],

        'OnlineTrainingOnlyForAuditors' => [
            'description' => 'Auditor are only allowed to take the Online Course',
            'type' => self::TYPE_BOOL,
            'default' => false
        ],

        'OnlineTrainingOnlyForBinaries' => [
            'description' => 'Only require Online Course and not In-Person training for vets (2+ years)',
            'type' => self::TYPE_BOOL,
            'default' => false
        ],

        'OnlineTrainingOnlyForVets' => [
            'description' => 'Only require the Online Course and not In-Person training for binaries (0-1 years)',
            'type' => self::TYPE_BOOL,
            'default' => false
        ],

        'OnlineTrainingFullCourseForVets' => [
            'description' => 'Require the full online course for vets',
            'type' => self::TYPE_BOOL,
            'default' => false
        ],

        'MoodleDomain' => [
            'description' => 'The LMS domain name',
            'type' => self::TYPE_STRING
        ],

        'MoodleToken' => [
            'description' => 'Moodle Web Service Token',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'MoodleHalfCourseId' => [
            'description' => 'Moodle online course ID for active Rangers (2+ years)',
            'type' => self::TYPE_STRING,
        ],

        'MoodleFullCourseId' => [
            'description' => 'Moodle full online course ID for PNVs, Auditors, Binaries, and Inactive Rangers',
            'type' => self::TYPE_STRING,
        ],

        'MoodleServiceName' => [
            'description' => 'Moodle service name to use',
            'type' => self::TYPE_STRING,
        ],

        'MoodleStudentRoleID' => [
            'description' => 'The LMS (Moodle) role id to assign to new users (usually student)',
            'type' => self::TYPE_INTEGER,
        ],

        'PersonnelEmail' => [
            'description' => 'Ranger Personnel Email Address',
            'type' => self::TYPE_EMAIL,
        ],

        'PhotoAnalysisEnabled' => [
            'description' => 'Run all uploaded photos through AWS Rekognition face detection',
            'type' => self::TYPE_BOOL,
        ],

        'PhotoRekognitionAccessKey' => [
            'description' => 'AWS Rekognition Access Key used for BMID photo analysis',
            'type' => self::TYPE_STRING,
            'is_credential' => true
        ],

        'PhotoRekognitionAccessSecret' => [
            'description' => 'AWS Rekognition Secret Key used for BMID photo analysis',
            'type' => self::TYPE_STRING,
            'is_credential' => true
        ],

        'PhotoPendingNotifyEmail' => [
            'description' => 'Email(s) to notify when photos are queued up for review. (nightly mail)',
            'type' => self::TYPE_EMAIL
        ],

        'PhotoUploadEnable' => [
            'description' => 'Enable Photo Uploading. If disabled, Admins and VCs will still be able to upload photos on another person\'s behalf.',
            'type' => self::TYPE_BOOL,
        ],

        'RadioCheckoutAgreementEnabled' => [
            'description' => 'Allows the Radio Checkout Agreement to be signed.',
            'type' => self::TYPE_BOOL,
        ],

        'RadioInfoAvailable' => [
            'description' => 'True if radio information has been uploaded.',
            'type' => self::TYPE_BOOL,
        ],

        'RangerFeedbackFormUrl' => [
            'description' => 'Ranger Feedback Form URL',
            'type' => self::TYPE_STRING,
        ],

        'RangerManualUrl' => [
            'description' => 'The current Ranger Manual document',
            'type' => self::TYPE_STRING
        ],

        'RangerPoliciesUrl' => [
            'description' => 'Ranger Policy Document URL',
            'type' => self::TYPE_STRING,
        ],

        'RangerPersonalVehiclePolicyUrl' => [
            'description' => 'Ranger Personal Vehicle Document URL (used by Me > Vehicles)',
            'type' => self::TYPE_STRING,
        ],

        'RpTicketThreshold' => [
            'description' => 'Credit threshold for a reduced price ticket. Shown on the Schedule and Ticket announce pages',
            'type' => self::TYPE_FLOAT,
        ],

        'SandmanRequireAffidavit' => [
            'description' => 'Require the Sandman Affidavit be signed in order to work a Sandman shift',
            'type' => self::TYPE_BOOL,
            'default' => 'false',
        ],

        'SandmanRequirePerimeterExperience' => [
            'description' => 'Require a Sandman to have worked a Burn Perimeter or Sandman positions within the last few years',
            'type' => self::TYPE_BOOL,
            'default' => 'false',
        ],

        'SFEnableWritebacks' => [
            'description' => 'Enable Salesforce Object Update',
            'type' => self::TYPE_BOOL,
        ],

        'SFprdAuthUrl' => [
            'description' => 'Salesforce Production Authentication URL',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'SFprdClientId' => [
            'description' => 'Salesforce Production Client ID',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'SFprdClientSecret' => [
            'description' => 'Salesforce Production Client Secret',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'SFprdPassword' => [
            'description' => 'Salesforce Production Password (login password + security token)',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'SFprdUsername' => [
            'description' => 'Salesforce Production Username',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'ScTicketThreshold' => [
            'description' => 'Credit threshold for staff credential. Shown on the Schedule and Ticket announce pages',
            'type' => self::TYPE_FLOAT,
        ],

        'SendWelcomeEmail' => [
            'description' => 'Enable Welcome email when an account is created',
            'type' => self::TYPE_BOOL,
        ],

        'ShiftSignupFromEmail' => [
            'description' => 'From email  address for shift sign up messages',
            'type' => self::TYPE_EMAIL,
        ],

        'ShirtLongSleeveHoursThreshold' => [
            'description' => 'Hour threshold to earn a long sleeve shirt',
            'type' => self::TYPE_INTEGER,
        ],

        'ShirtShortSleeveHoursThreshold' => [
            'description' => 'Hour threshold to earn a short sleeve shirt/t-shirt',
            'type' => self::TYPE_INTEGER,
        ],

        'ShowerPogThreshold' => [
            'description' => 'Hour threshold to earn a shower pog to The Wet Spot',
            'type' => self::TYPE_INTEGER
        ],

        'ShowerAccessThreshold' => [
            'description' => 'Hour threshold to earn shower access for the next event',
            'type' => self::TYPE_INTEGER,
        ],

        'TAS_Alpha_FAQ' => [
            'description' => 'Alpha WAP FAQ Link',
            'type' => self::TYPE_STRING,
        ],

        'TAS_BoxOfficeOpenDate' => [
            'description' => 'Playa Box Office Opening date and time',
            'type' => self::TYPE_DATETIME,
        ],

        'TAS_DefaultAlphaWAPDate' => [
            'description' => 'Default Alpha WAP Access Date',
            'type' => self::TYPE_DATE,
        ],

        'TAS_DefaultSOWAPDate' => [
            'description' => 'Default WAP SO Access Date',
            'type' => self::TYPE_DATE,
        ],

        'TAS_DefaultWAPDate' => [
            'description' => 'Default WAP Access Date',
            'type' => self::TYPE_DATE,
        ],

        'TAS_Delivery' => [
            'description' => 'Ticket Delivery View',
            'type' => self::TYPE_STRING,
            'options' => [
                ['none', 'not available yet'],
                ['view', 'ticket announcement'],
                ['accept', 'allow ticket submissions'],
                ['frozen', 'ticket window is closed'],
            ]
        ],

        'TAS_Email' => [
            'description' => 'Ranger Ticketing Support Email',
            'type' => self::TYPE_EMAIL,
        ],

        'TAS_PayByDateTime' => [
            'description' => 'The date and time items have to be paid for. Shown on the Ticketing is closed page',
            'type' => self::TYPE_DATETIME,
        ],

        'TAS_Pickup_Locations' => [
            'description' => 'Locations w/hours to pickup staff credentials and will-call items. Shown on the ticketing page',
            'type' => self::TYPE_STRING,
        ],

        'TAS_SubmitDate' => [
            'description' => 'Ticketing Submission Deadline',
            'type' => self::TYPE_STRING,
        ],

        'TAS_Ticket_FAQ' => [
            'description' => 'Ticketing FAQ Link',
            'type' => self::TYPE_STRING,
        ],

        'TAS_Tickets' => [
            'description' => 'Event Ticket Mode',
            'type' => self::TYPE_STRING,
            'options' => [
                ['none', 'not available yet'],
                ['view', 'ticket announcement'],
                ['accept', 'allow ticket submissions'],
                ['frozen', 'ticket window is closed'],
            ]
        ],

        'TAS_VP' => [
            'description' => 'Vehicle Pass Mode',
            'type' => self::TYPE_STRING,
            'options' => [
                ['none', 'not available yet'],
                ['view', 'ticket announcement'],
                ['accept', 'allow ticket submissions'],
                ['frozen', 'ticket window is closed'],
            ]
        ],

        'TAS_VP_FAQ' => [
            'description' => 'Vehicle Pass FAQ Link',
            'type' => self::TYPE_STRING,
        ],

        'TAS_WAP' => [
            'description' => 'Work Access Pass Mode',
            'type' => self::TYPE_STRING,
            'options' => [
                ['none', 'not available yet'],
                ['view', 'ticket announcement'],
                ['accept', 'allow ticket submissions'],
                ['frozen', 'ticket window is closed'],
            ]
        ],

        'TAS_WAPDateRange' => [
            'description' => 'WAP allowable date range. Format: MM/DD-MM/DD',
            'type' => self::TYPE_STRING,
        ],

        'TAS_WAPSO' => [
            'description' => 'WAP SO Mode',
            'type' => self::TYPE_STRING,
            'options' => [
                ['none', 'not available yet'],
                ['view', 'ticket announcement'],
                ['accept', 'allow ticket submissions'],
                ['frozen', 'ticket window is closed'],
            ]
        ],

        'TAS_WAPSOMax' => [
            'description' => 'Max. WAP SO Count',
            'type' => self::TYPE_INTEGER,
        ],

        'TAS_WAP_FAQ' => [
            'description' => 'WAP FAQ Link',
            'type' => self::TYPE_STRING,
        ],

        'TAS_WAP_Report_Email' => [
            'description' => 'Email address(es) to send nightly WAPS Needed Report to',
            'type' => self::TYPE_EMAIL
        ],

        'ThankYouCardsHash' => [
            'description' => 'Thank You card page password. SHA-256 encoded.',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'TicketVendorEmail' => [
            'description' => 'Ticketing Vendor Support Email',
            'type' => self::TYPE_EMAIL,
        ],

        'TicketVendorName' => [
            'description' => 'Ticketing Vendor Name',
            'type' => self::TYPE_STRING,
        ],

        'TicketingPeriod' => [
            'description' => 'Ticketing Period / Season',
            'type' => self::TYPE_STRING,
            'options' => [
                ['offseason', 'off season - show banked tickets'],
                ['announce', 'announce - tickets have been awarded but ticketing window is not open'],
                ['open', 'open - tickets can be claimed and TAS_Tickets, TAS_VP, TAS_WAP, TAS_WAPSO, TAS_Delivery come into play'],
                ['closed', 'closed - show claims and banks. Changes not directly allowed'],
            ]
        ],

        'TicketsAndStuffEnablePNV' => [
            'description' => 'Enable Ticketing Page for PNVs',
            'type' => self::TYPE_BOOL,
        ],

        'TimesheetCorrectionEnable' => [
            'description' => 'Allow users to submit Timesheet Corrections',
            'type' => self::TYPE_BOOL,
        ],

        'TrainingSeasonalRoleEnabled' => [
            'description' => "Enable Training Seasonal Role",
            'type' => self::TYPE_BOOL,
            'default' => 'false',
        ],

        'TrainingAcademyEmail' => [
            'description' => 'Training Academy Email',
            'type' => self::TYPE_EMAIL,
        ],

        'TrainingSignupFromEmail' => [
            'description' => 'From email address for training sign up messages',
            'type' => self::TYPE_EMAIL,
        ],

        'TwilioAccountSID' => [
            'description' => 'Twilio Account SID',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'TwilioAuthToken' => [
            'description' => 'Twilio Authentication Token',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'TwilioServiceId' => [
            'description' => 'Twilio Service ID of SMS Channel',
            'type' => self::TYPE_STRING,
            'is_credential' => true,
        ],

        'VCEmail' => [
            'description' => 'Ranger Volunteer Coordinator Address',
            'type' => self::TYPE_EMAIL,
        ],

        'VehiclePendingEmail' => [
            'description' => 'Email(s) to notify when vehicle requests are queued up for review. (nightly mail)',
            'type' => self::TYPE_EMAIL
        ],

        'LoginManageOnPlayaEnabled' => [
            'description' => 'Enables Login Manage On Playa role AND allows LM Year Round to view Emergency Contact Info plus read Clubhouse Messages',
            'type' => self::TYPE_BOOL
        ]
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (isset($this->name) && !isset($this->value) && isset(self::DESCRIPTIONS[$this->name]['default'])) {
            $this->value = self::DESCRIPTIONS[$this->name]['default'];
        }
    }

    /*
     * Find a setting. Must be defined in the DESCRIPTIONS table
     */

    public static function find($name)
    {
        $desc = self::DESCRIPTIONS[$name] ?? null;

        if (!$desc) {
            // Setting is not defined.
            return null;
        }

        // Lookup the value
        return Setting::firstOrNew(['name' => $name]);
    }

    public static function findOrFail($name)
    {
        $row = self::find($name);

        if ($row) {
            return $row;
        }

        throw (new ModelNotFoundException)->setModel(Setting::class, $name);
    }

    public static function findAll()
    {
        $rows = Setting::all()->keyBy('name');

        $settings = [];
        foreach (self::DESCRIPTIONS as $name => $desc) {
            $settings[] = $rows[$name] ?? new Setting(['name' => $name]);
        }

        usort($settings, fn($a, $b) => strcasecmp($a->name, $b->name));

        return $settings;
    }

    public static function kickQueues()
    {
        if (!app()->isLocal()) {
            try {
                // Kick the queue workers to pick up the new settings
                Artisan::call('queue:restart');
            } catch (Exception $e) {
                ErrorLog::recordException($e, 'setting-queue-restart-exception');
            }
        }
    }

    public static function getValue($name, $throwOnEmpty = false)
    {
        if (is_array($name)) {
            $rows = self::select('name', 'value')->whereIn('name', $name)->get()->keyBy('name');
            $settings = [];
            foreach ($name as $setting) {
                $row = $rows[$setting] ?? null;
                $desc = self::DESCRIPTIONS[$setting] ?? null;
                if (!$desc) {
                    throw new InvalidArgumentException("'$setting' is an unknown setting.");
                }

                if (isset(self::$cache[$setting])) {
                    $settings[$setting] = self::$cache[$setting];
                } else {
                    if ($row) {
                        $value = self::castValue($desc['type'], $row->value);
                    } else if (isset($desc['default'])) {
                        $value = self::castValue($desc['type'], $desc['default']);
                    } else {
                        $value = null;
                    }

                    if ($throwOnEmpty && self::notEmpty($value)) {
                        throw new RuntimeException("Setting '$setting' is empty.");
                    }
                    $settings[$setting] = $value;
                    self::$cache[$setting] = $value;
                }
            }

            return $settings;
        } else {
            $desc = self::DESCRIPTIONS[$name] ?? null;
            if (!$desc) {
                throw new InvalidArgumentException("'$name' is an unknown setting.");
            }

            if (isset(self::$cache[$name])) {
                return self::$cache[$name];
            }

            $row = self::select('value')->where('name', $name)->first();
            if ($row) {
                $value = self::castValue($desc['type'], $row->value);
            } else if (isset($desc['default'])) {
                $value = self::castValue($desc['type'], $desc['default']);
            } else {
                $value = null;
            }
            if ($throwOnEmpty && self::notEmpty($value)) {
                throw new RuntimeException("Setting '$name' is empty.");
            }
            self::$cache[$name] = $value;
            return $value;
        }
    }

    public static function castValue($type, $value)
    {
        // Convert the values
        switch ($type) {
            case self::TYPE_BOOL:
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case self::TYPE_INTEGER:
                return (int)$value;
            case self::TYPE_FLOAT:
                return (float)$value;
            default:
                return $value;
        }
    }

    public static function notEmpty($value)
    {
        return !is_bool($value) && empty($value);
    }

    public function getTypeAttribute()
    {
        return self::DESCRIPTIONS[$this->name]['type'] ?? null;
    }

    public function getDescriptionAttribute()
    {
        return self::DESCRIPTIONS[$this->name]['description'] ?? null;
    }

    public function getOptionsAttribute()
    {
        return self::DESCRIPTIONS[$this->name]['options'] ?? null;
    }

    public function getIsCredentialAttribute()
    {
        return self::DESCRIPTIONS[$this->name]['is_credential'] ?? false;
    }
}
