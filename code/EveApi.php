<?php

require_once('../mysite/thirdparty/ale/factory.php');

class EveApi extends DataObject {
    public $ale;

    function __construct($record = null, $isSingleton = false) {
        $this->ale = AleFactory::getEVEOnline();
        return parent::__construct($record, $isSingleton);
    }

    static $db = array(
        'KeyID' => 'Int',
        'vCode' => 'Varchar(255)'
    );

    static $has_one = array(
        'Member' => 'Member'
    );

    static $summary_fields = array(
        'Pilot',
        'KeyID',
        'vCode'
    );

    static $casting = array(
        'Pilot' => 'Varchar(255)'
    );

    static $default_sort = "Created ASC";

    function Pilot()
    {
        return ($m = Member::get_by_id('Member', (int)$this->MemberID)) ? $m->NickName() : 'Unassigned';
    }

    function isValid()
    {
        if(!$this->Member()) return new DataObjectSet(array(array('Reason' => 'No Assoc Member')));
        if(time() - strtotime($this->Member()->LastVisited) > (86400 * 30)) {
            return new DataObjectSet(array(array('Reason' => 'Member has not logged in for one month')));
        }

        $errors = array();
        try {
            $this->ale->setKey($this->KeyID, $this->vCode);

            $info = $this->ale->Account->APIKeyInfo();

            // check  is account
            $info = $info->result->key->attributes();
            if($info['type'] != 'Account' && $info['type'] != 'Corporation') {
                $errors[] = array('Reason' => 'Key must be Account or Corp Key');
            }

            // check no expire
            if(strlen($info['expires']) > 1) {
                $errors[] = array('Reason' => 'Key must not Expire');
            }

            // check access mask
            $required = array(
                'AccountBalance' => 1,
                'CharacterInfo' => 16777216,
                'CharacterSheet' => 8,
                'CharacterInfo' => 8388608,
                'KillLog' => 256,
                'FacWarStats' => 64
            );

            foreach($required as $k => $v) {
                if(!((int)$info['accessMask'] & $v)) {
                    $errors[] = array('Reason' => 'Missing '.$k);
                }
            }
        } catch(Exception $e) {
            $errors[] = array('Reason' => $e->getMessage());
        }
        return (count($errors) > 0) ? new DataObjectSet($errors) : true;
    }

    function hasAccess($mask = 0)
    {
        /* need to call this from isValid, so prob  rework this */
        $isValid = $this->isValid();
        $errors = ($isValid !== true) ?  array() : array();

        try {
            $this->ale->setKey($this->KeyID, $this->vCode);
            $info = $this->ale->Account->APIKeyInfo();
            $info = $info->result->key->attributes();

            if(!((int)$info['accessMask'] & $mask)) {
                $errors[] = array('Reason' => 'Missing ' . $mask);
            }
        } catch (Exception $e) {
            $errors[] = array('Reason' => 'Invalid Key');
        }

        return (count($errors) > 0) ? new DataObjectSet($errors) : true;
    }

    function Characters()
    {
        if($this->isValid() !== true) return array();
        $chars = array();

        $this->ale->setKey($this->KeyID, $this->vCode);
        try {
            $info = $this->ale->Account->Characters();
        } catch(Exception $e) {
            return $chars;
        }

        foreach($info->result->characters as $c) {
            $chars[] = $c->attributes();
        }

        return $chars;
    }

    function ApiSecurityGroups()
    {
        $groups = array();
        $rank = array(99 => 'Visitor');

        if($this->isValid() !== true) return array('Groups' => $groups, 'Rank' => $rank);

        foreach($this->Characters() as $c) {
            // first check corp
            if($c['corporationID'] == 98045653) {
                $groups[] = 'rioters';
                $rank[90] = 'Rioter';

            /*
            // no more rint
            } elseif($c['corporationID'] == 98140983) {
                $groups[] = 'recruits';
                $rank[95] = 'Recruit';
            */

            } else {
                continue;
            }

            $this->ale->setCharacterID($c['characterID']);
            try {
                $ci = $this->ale->char->CharacterSheet();
                $roles = $ci->xpath("/eveapi/result/rowset[@name='corporationRoles']/row");
                foreach($roles as $r) {
                    //print_r($r);
                    $r = $r->attributes();
                    // check for role based access (officer, director)
                    if($r['roleID'] == 1 && in_array('rioters', $groups)) {
                        $groups[] = 'officers';
                        $groups[] = 'directors';
                        $rank[10] = 'Director';
                    }
                   //print_r($groups);
                }
                $titles = $ci->xpath("/eveapi/result/rowset[@name='corporationTitles']/row");
                foreach($titles as $t) {
                    //print_r($t);
                    $t = $t->attributes();
                    if(($t['titleName'] == 'Officer' || $t['titleName'] == 'Enforcer')
                        && in_array('rioters', $groups)
                    ) {
                        $groups[] = 'officers';
                        $rank[20] = 'Officer';
                    }
                }
                if($c['name'] == 'Wishdokkta CEO') {
                    $rank[0] = 'CEO';
                }
            } catch(Exception $e) {
                continue;
            }
        }
        ksort($rank);
        return array('Groups' => $groups, 'Rank' => $rank);
    }

    function onAfterWrite()
    {
        $m = Member::get_by_id('Member', (int)$this->MemberID);
        if($m) $m->updateGroupsFromAPI();
        return parent::onAfterWrite();
    }

    function onAfterDelete()
    {
        $m = Member::get_by_id('Member', (int)$this->MemberID);
        if($m) $m->updateGroupsFromAPI();
        return parent::onAfterDelete();
    }
}
