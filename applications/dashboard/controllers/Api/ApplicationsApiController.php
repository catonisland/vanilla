<?php
/**
* @copyright 2009-2017 Vanilla Forums Inc.
* @license GPLv2
*/

use Garden\Schema\Schema;
use Garden\Web\Data;
use Garden\Web\Exception\ClientException;
use Garden\Web\Exception\NotFoundException;
use Garden\Web\Exception\ServerException;
use Vanilla\Utility\CapitalCaseScheme;

/**
 * API Controller for managing applications.
 */
class ApplicationsApiController extends AbstractApiController {

    /** @var CapitalCaseScheme */
    private $caseScheme;

    /** @var Schema */
    private $idParamSchema;

    /** @var UserModel */
    private $userModel;

    /**
     * ApplicationsApiController constructor.
     *
     * @param CapitalCaseScheme $caseScheme
     * @param Gdn_Configuration $configuration
     * @param UserModel $userModel
     * @throws ClientException if the registration method on the site is not approval.
     */
    public function __construct(CapitalCaseScheme $caseScheme, Gdn_Configuration $configuration, UserModel $userModel) {
        $registrationMethod = strtolower($configuration->get('Garden.Registration.Method'));
        if ($registrationMethod !== 'approval') {
            throw new ClientException('The site is not configured for the approval registration method.');
        }
        $this->caseScheme = $caseScheme;
        $this->userModel = $userModel;
    }

    /**
     * Delete an application. For now, this will decline the user's application.
     *
     * @param int $id The ID of the application.
     * @throws ClientException if the application has been approved.
     */
    public function delete($id) {
        $this->permission('Garden.Users.Approve');

        $in = $this->idParamSchema()->setDescription('Delete an application.');
        $out = $this->schema([], 'out');

        $row = $this->userByID($id);
        $this->prepareRow($row);

        if ($this->userModel->isApplicant($id) === false) {
            throw new ClientException('The application specified is already an active user.');
        }

        $this->userModel->decline($id);
    }

    /**
     * Get a schema instance comprised of all available application fields.
     *
     * @return Schema Returns a schema object.
     */
    public function fullSchema() {
        static $fullSchema;

        if ($fullSchema === null) {
            $fullSchema = Schema::parse([
                'applicationID:i' => 'Unique ID associated with the application.',
                'email:s' => 'Email address associated with the application.',
                'name:s' => 'Username on the application.',
                'discoveryText:s' => 'Reason why you want to join.',
                'status:s' => [
                    'description' => 'Current status of the application.',
                    'enum' => ['approved', 'declined', 'pending']
                ],
                'insertIPAddress:s' => 'IP address of the user who created the application.',
                'dateInserted:dt' => 'When the application was created.'
            ]);
        }

        return $fullSchema;
    }

    /**
     * Get a single application.
     *
     * @param int $id The ID of the application.
     * @throws NotFoundException if the application could not be found.
     * @return array
     */
    public function get($id) {
        $this->permission('Garden.Users.Approve');

        $in = $this->idParamSchema()->setDescription('Get an application.');
        $out = $this->schema($this->fullSchema(), 'out');

        $row = $this->userByID($id);
        $this->prepareRow($row);

        $result = $out->validate($row);
        return $result;
    }

    /**
     * Get an ID-only application record schema.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function idParamSchema($type = 'in') {
        if ($this->idParamSchema === null) {
            $this->idParamSchema = $this->schema(
                Schema::parse(['id:i' => 'The application ID.']),
                $type
            );
        }
        return $this->schema($this->idParamSchema, $type);
    }

    /**
     * Get a list of current applicants.
     *
     * @return array
     */
    public function index(array $query) {
        $this->permission('Garden.Users.Approve');

        $in = $this->schema([
            'page:i?' => [
                'description' => 'Page number.',
                'default' => 1,
                'minimum' => 1,
                'maximum' => 100
            ],
            'limit:i?' => [
                'description' => 'The number of items per page.',
                'default' => 30,
                'minimum' => 1,
                'maximum' => 100
            ]
        ], 'in')->setDescription('Get a list of current applications.');
        $out = $this->schema(
            [':a' => $this->fullSchema()],
            'out'
        );

        $query = $in->validate($query);

        list($offset, $limit) = offsetLimit("p{$query['page']}", $query['limit']);
        $rows = $this->userModel->getApplicants($limit, $offset)->resultArray();

        foreach ($rows as &$row) {
            $this->prepareRow($row);
        }

        $result = $out->validate($rows);
        return $result;
    }

    /**
     * Approve or decline an application.
     *
     * @param int $id
     * @param array $body
     * @throws ClientException if the user record is not an applicant.
     * @return array
     */
    public function patch($id, array $body) {
        $this->permission('Garden.Users.Approve');

        $this->idParamSchema('in');
        $in = $this->schema([
            'status:s' => [
                'description' => 'Current status of the application.',
                'enum' => ['approved', 'declined']
            ]
        ], 'in')->setDescription('Modify an application.');
        $out = $this->schema($this->fullSchema(), 'out');

        $row = $this->userByID($id);
        $this->prepareRow($row);

        if ($this->userModel->isApplicant($id) === false) {
            throw new ClientException('The application specified is already an active user.');
        }

        $body = $in->validate($body, true);

        if (array_key_exists('status', $body)) {
            switch ($body['status']) {
                case 'approved':
                    if ($this->userModel->approve($id)) {
                        $row['status'] = 'approved';
                    }
                    break;
                case 'declined':
                    if ($this->userModel->decline($id)) {
                        $row['status'] = 'declined';
                    }
                    break;
            }
        }

        $this->validateModel($this->userModel);
        $result = $out->validate($row);
        return $result;
    }

    /**
     * Submit an application.
     *
     * @param array $body
     * @throws ClientException if the terms of service flag is false.
     * @throws ServerException if a valid user ID is not returned when creating the applicant record.
     * @return Data
     */
    public function post(array $body) {
        $this->permission(\Vanilla\Permissions::BAN_CSRF);

        $in = $this->schema([
            'email:s' => 'The email address for the user.',
            'name:s' => 'A username for the user.',
            'password:s' => 'A password for the user.',
            'discoveryText:s' => 'Why does the user wish to join?'
        ], 'in')->setDescription('Submit an application.');
        $out = $this->schema($this->fullSchema(), 'out');

        $body = $in->validate($body);

        $this->userModel->validatePasswordStrength($body['password'], $body['name']);

        $userData = $this->caseScheme->convertArrayKeys($body);
        $userID = $this->userModel->register($userData);
        $this->validateModel($this->userModel);

        if (filter_var($userID, FILTER_VALIDATE_INT)) {
            $row = $this->userByID($userID);
        } else {
            throw new ServerException('An unknown error occurred while attempting to create the application.', 500);
        }
        $this->prepareRow($row);

        $result = $out->validate($row);
        return $result;
    }

    /**
     * Prepare the current row for output.
     *
     * @param array $row
     */
    public function prepareRow(array &$row) {
        $row['ApplicationID'] = $row['UserID'];
        unset($row['UserID']);
        $row['Status'] = 'pending';
    }

    /**
     * Get an application by its numeric ID.
     *
     * @param int $id The user ID.
     * @throws NotFoundException if the user could not be found.
     * @return array
     */
    public function userByID($id) {
        $row = $this->userModel->getID($id, DATASET_TYPE_ARRAY);
        if (!$row || $row['Deleted'] > 0) {
            throw new NotFoundException('Application');
        }
        return $row;
    }
}
