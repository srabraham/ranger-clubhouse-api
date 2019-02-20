<?php

// ------------- PotentialClubhouseAccountFromSalesforce class ----------

namespace App\Lib;

class PotentialClubhouseAccountFromSalesforce
{
    const STATUS_NULL = "null";
    const STATUS_NOTREADY = "notready";
    const STATUS_INVALID = "invalid";
    const STATUS_READY = "ready";
    const STATUS_IMPORTED = "imported";
    const STATUS_SUCCEEDED = "succeeded";

    const REQUIRED_RANGER_INFO_FIELDS = array(
            'FirstName',
            'LastName',
            'MailingStreet',
            'MailingCity',
            'MailingState',
            'MailingCountry',
            'MailingPostalCode',
            'npe01__HomeEmail__c',
            'Phone',
    //                'Birthdate',
            'BPGUID__c',
            'SFUID__c',
            'Emergency_Contact_Name__c',
            'Emergency_Contact_Phone__c',
            'Emergency_Contact_Relationship__c',
    // The following fields exist but are always blank, at least in the
    // sandbox, so we ignore them
    //            'Email',
    //            'npe01__WorkEmail__c',
    //            'npe01__Preferred_Email__c',
    //            'npe01__PreferredPhone__c',
    //            'npe01__AlternateEmail__c',
    //            'npe01__WorkPhone__c',
    //            'MobilePhone',
    //            'OtherPhone',
    );

    public $status;     /* "null", "invalid", "ready", "imported", "succeeded" */
    public $message;
    public $applicant_type;
    public $salesforce_ranger_object_id;        /* Internal Salesforce ID */
    public $salesforce_ranger_object_name;      /* "R-201" etc. */
    public $firstname;
    public $lastname;
    public $street1;
    public $city;
    public $state;
    public $zip;
    public $country;
    public $phone;
    public $email;
    public $emergency_contact;
    public $bpguid;
    public $sfuid;
    public $chuid;
    public $longsleeveshirt_size_style;     /* Fixed to remove multibyte crap */
    public $teeshirt_size_style;            /* Fixed to remove multibyte crap */
    public $known_pnv_names;        /* PNV = prospective new volunteers */
    public $known_ranger_names;
    public $callsign;
    public $vc_status;

    public function __construct()
    {
        $this->status = self::STATUS_NOTREADY;     /* placeholder */
        $this->message = "";
    }

    /*
    * Convert a potential Ranger account from Salesforce into the info
    * we need to create a Clubhouse account, sanity checking as we go.
    *
    * "sobj" is a Salesforce object, with all of its horrible naming.
    * We populate the fields in $this (which have more sane names) and
    * clean up the data.
    *
    * At the end, $this->status and $this->message are set accordingly,
    * depending on whether this potential account is good to go.
    *
    * Return TRUE if ok to import, FALSE otherwise.
    */
    public function convertFromSalesforceObject($sobj)
    {
        /*
        * The following just copies the various fields from the SF object
        * into more reasonably named fields, so that higher levels in the
        * Clubhouse don't have to be aware of the cthuluhian horror of the
        * Salesforce object naming scheme.
        */
        $this->salesforce_ranger_object_name = trim(@$sobj->Name);
        $this->salesforce_ranger_object_id = trim(@$sobj->Id);
        $this->applicant_type = trim(@$sobj->Ranger_Applicant_Type__c);
        $this->firstname = trim(@$sobj->Ranger_Info__r->FirstName);
        $this->lastname = trim(@$sobj->Ranger_Info__r->LastName);
        $this->street1 = self::sanitizeStreet(trim(@$sobj->Ranger_Info__r->MailingStreet));
        $this->city = trim(@$sobj->Ranger_Info__r->MailingCity);
        $this->state = trim(@$sobj->Ranger_Info__r->MailingState);
        $this->zip = trim(@$sobj->Ranger_Info__r->MailingPostalCode);
        $this->country = trim(@$sobj->Ranger_Info__r->MailingCountry);
        $this->phone = trim(@$sobj->Ranger_Info__r->Phone);
        $this->email = trim(@$sobj->Ranger_Info__r->npe01__HomeEmail__c);
        $this->emergency_contact =
            trim(@$sobj->Ranger_Info__r->Emergency_Contact_Name__c)
            . " ("
            . trim(@$sobj->Ranger_Info__r->Emergency_Contact_Relationship__c)
            . "), phone "
            . trim(@$sobj->Ranger_Info__r->Emergency_Contact_Phone__c);
        $this->bpguid = trim(@$sobj->Ranger_Info__r->BPGUID__c);
        $this->sfuid = trim(@$sobj->Ranger_Info__r->SFUID__c);
        $this->chuid = trim(@$sobj->CH_UID__c);
        $this->longsleeveshirt_size_style = self::sanitizeLongsleeveshirtSizeStyle(trim(@$sobj->Long_Sleeve_Shirt_Size__c));
        $this->teeshirt_size_style = self::sanitizeTeeshirtSizeStyle(trim(@$sobj->Tee_Shirt_Size__c));
        $this->known_pnv_names = trim(@$sobj->Known_Prospective_Volunteer_Names__c);
        $this->known_ranger_names = trim(@$sobj->Known_Rangers_Names__c);
        $this->callsign = trim(@$sobj->VC_Approved_Radio_Call_Sign__c);
        $this->vc_status = trim(@$sobj->VC_Status__c);

        if ($this->vc_status == "Released to Upload"
            && $this->applicant_type == "Prospective New Volunteer - Black Rock Ranger"
        ) {
            $this->status = Person::STATUS_READY;
        }

        if ($this->callsign == "") {
            $this->status = Person::STATUS_INVALID;
            $this->message =
                    "VC_Approved_Radio_Call_Sign is blank";
            return false;
        }

        $ok = true;
        foreach (self::REQUIRED_RANGER_INFO_FIELDS as $req) {
            if (!isset($sobj->Ranger_Info__r->$req)) {
                $this->status = Person::STATUS_INVALID;
                $this->message =
                    "Missing required field $req";
                $ok = false;
                break;
            }
            $x = trim($sobj->Ranger_Info__r->$req);
            if ($x == "") {
                $this->status = "invalid";
                $this->message =
                    "Blank required field $req";
                $ok = false;
                break;
            }
        }

        return $ok;
    }

    /*
    * See if this account already exists in some form.
    * This means: (1) callsign is unique, (2) email is unique, (3) bpguid is
    * unique, (4) sfguid is unique.
    * Sets this->status and this->message appropriately.
    * Only do this for accounts that are presumed ready for import.
    */
    public function checkIfAlreadyExists()
    {
        if ($this->status != self::STATUS_READY) {
            return;
        }

        if ($this->callsignAlreadyExists()) {
            $this->status = "already_exists_callsign";
            $this->message = "Clubhouse account with this callsign already exists";
            return;
        }
        if ($this->callsignIsReserved()) {
            $this->status = "reserved_callsign";
            $this->message = "Callsign is on the reserved list";
            return;
        }
        if ($this->emailAlreadyExists()) {
            $this->status = "already_exists_email";
            $this->message = "Clubhouse account with this email address already exists";
            return;
        }
        if ($this->bpguidAlreadyExists()) {
            $this->status = "already_exists_bpguid";
            $this->message = "Clubhouse account with this BPGUID already exists";
            return;
        }
        if ($this->sfuidAlreadyExists()) {
            $this->status = "already_exists_sfuid";
            $this->message = "Clubhouse account with this SFUID already exists";
            return;
        }
    }

    /*
    * See if an account with this exact email exists.
    * Return TRUE if so, FALSE otherwise.
    */
    public function emailAlreadyExists()
    {
        return Person::where('email', $this->email)->exists();
    }

    /*
    * See if an account with this exact callsign exists.
    * Return TRUE if so, FALSE otherwise.
    */
    public function callsignAlreadyExists()
    {
        return Person::where('callsign', $this->callsign)->exists();
    }

    /*
    * See if this callsign is on the reserved List.
    * Return TRUE if so, FALSE otherwise.
    */
    public function callsignIsReserved()
    {
        return Security::checkIfCallsignReserved($this->callsign);
    }

    /*
    * See if an account with this exact BPGUID already exists.
    * Return TRUE if so, FALSE otherwise.
    */
    public function bpguidAlreadyExists()
    {
        return Person::where('bpguid', $this->bpguid)->exists();
    }

    /*
    * See if an account with this exact SFUID already exists.
    * Return TRUE if so, FALSE otherwise.
    */
    public function sfuidAlreadyExists()
    {
        return Person::where('sfuid', $this->sfuid)->exists();
    }

    /*
    * Street addresses in salesforce can contain \r\n,
    * so we get rid of the \rs and convert the \ns to space.
    */
    public static function sanitizeStreet($s)
    {
        $s = str_replace("\r", "", $s);
        $s = str_replace("\n", " ", $s);
        return trim($s);
    }

    public static function sanitizeTeeshirtSizeStyle($s)
    {
        $s = self::fixMultibyteCrap($s);
        $s = str_replace("Tee ", "", $s);       // Gag.
        return $s;
    }

    public static function sanitizeLongsleeveshirtSizeStyle($s)
    {
        return self::fixMultibyteCrap($s);
    }

    /*
    * Shirt size and style from Salesforce uses multibyte strings.
    * In particular, 0xe28099 for an apostrophe and, occasionally,
    * 0xc2a0 for non-breaking space.  What did I do wrong such that
    * I'm spending a sunny Saturday afternoon dealing with this crap?
    */
    public static function fixMultibyteCrap($s)
    {
        $x = mb_ereg_replace("’", '', $s);
        $x = str_replace("\xc2\xa0", ' ', $x);
        return $x;
    }
}
