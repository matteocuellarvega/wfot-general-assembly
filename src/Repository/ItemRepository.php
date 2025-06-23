<?php
namespace WFOT\Repository;

use WFOT\Services\AirtableService;

class ItemRepository
{
    private AirtableService $db;
    const TABLE = 'tblT0M8sYqgHq6Tsa';

    public function __construct()
    {
        $this->db = new AirtableService();
    }

    public function listForMeeting(string $meetingId, string $role): array
    {
        $formula = sprintf(
            "AND({Meeting ID}='%s', FIND('%s',{Available To})>0)",
            $meetingId,
            in_array($role,['Delegate','1st Alternate','2nd Alternate','Regional Group Representative']) ? 'Key Person' : 'Observer'
        );
        $records = [];

        foreach ($this->db->all(self::TABLE, ['filterByFormula'=>$formula]) as $r) {
            $records[]=$r;
        }
        usort($records, fn($a,$b)=>[$a['fields']['Type'],$a['fields']['Name']] <=> [$b['fields']['Type'],$b['fields']['Name']]);
        return $records;
    }
}
?>
