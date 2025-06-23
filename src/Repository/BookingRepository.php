<?php
namespace WFOT\Repository;

use WFOT\Services\AirtableService;

class BookingRepository
{
    private AirtableService $db;
    const TABLE = 'tblETcytPcj835rb0';

    public function __construct()
    {
        $this->db = new AirtableService();
    }

    public function find(string $id)
    {
        return $this->db->find(self::TABLE, $id);
    }

    public function findByRegistration(string $regId)
    {
        $records = $this->db->all(self::TABLE,[
            'filterByFormula' => sprintf("ARRAYJOIN({Registration})='%s'", $regId),
            'maxRecords'=>1
        ]);
        foreach($records as $r) return $r;
        return null;
    }

    public function create(string $regId)
    {
        return $this->db->create(self::TABLE,[
            'Registration'=>[$regId],
            'Status'=>'Pending',
            'Payment Status'=>'Pending'
        ]);
    }

    public function update(string $id, array $fields)
    {
        return $this->db->update(self::TABLE, $id, $fields);
    }
}
?>
