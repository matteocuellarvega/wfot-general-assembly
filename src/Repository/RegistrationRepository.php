<?php
namespace WFOT\Repository;

use WFOT\Services\AirtableService;

class RegistrationRepository
{
    private AirtableService $db;
    private const TABLE = 'tblxFb5zXR3ZKtaw9';

    public function __construct()
    {
        $this->db = new AirtableService();
    }

    /**
     * Fetch a single Registration record.
     *
     * @return array|null   Example successful shape:
     *                      [
     *                          'id'     => 'recABC123',
     *                          'fields' => [ 'First Name' => 'Matt', … ]
     *                      ]
     */
    public function find(string $id): ?array
    {
        return $this->db->find(self::TABLE, $id);   // <-- direct helper call
    }

    /**
     * Example of a filtered lookup (if you ever need it):
     */
    public function findByEmail(string $email): ?array
    {
        $records = $this->db->all(self::TABLE, [
            'filterByFormula' => sprintf("{Email} = '%s'", addslashes($email)),
            'maxRecords'      => 1,
        ]);

        return $records[0] ?? null;
    }
}