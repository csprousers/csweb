<?php

namespace App\Controller\ui;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use App\Service\HttpHelper;
use App\Service\PdoHelper;
use App\CSPro\CSProResponse;
use App\CSPro\RolesRepository;
use App\CSPro\User\RolePermissions;
use App\CSPro\User\RoleDictionaryPermissions;
use App\CSPro\User\Role;
use App\Security\RolesVoter;

class RoleController extends AbstractController implements TokenAuthenticatedController {

    private $rolesRepository;

    public function __construct(private HttpHelper $client, private PdoHelper $pdo, private LoggerInterface $logger) {

    }

    //overrider the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null): ?ContainerInterface {
        $this->rolesRepository = new RolesRepository($this->pdo, $this->logger);
        return parent::setContainer($container);
    }

    #[Route('/roles', name: 'roles', methods: ['GET'])]
    public function viewRoleListAction(Request $request): Response {

        $this->denyAccessUnlessGranted(RolesVoter::ROLES_READ);
        return $this->render('roles.twig', []);
    }

    #[Route('/getRoles', name: 'get-roles', methods: ['GET'])]
    public function getRoles(Request $request): Response {

        $this->denyAccessUnlessGranted(RolesVoter::ROLES_READ);

        $roles = $this->rolesRepository->getRoles();
        $this->logger->debug((is_countable($roles) ? count($roles) : 0) . ' the roles are' . json_encode($roles, JSON_THROW_ON_ERROR));
        $roleStrings = array_map(fn($r) => $r->toJSON(), $roles);
        $rolesJSONString = '[' . implode(',', $roleStrings) . ']';
        $response = new Response($rolesJSONString);
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/getDictionaryPermissions', name: 'get-dictionary-permissions', methods: ['GET'])]
    public function getDictionaryPermissions(Request $request): Response {
        $this->denyAccessUnlessGranted(RolesVoter::ROLES_READ);

        $rowNumber = $request->get('rowNumber');

        if (isset($rowNumber)) {
            $roles = $this->rolesRepository->getRoles();
            //$this->logger->error('getRoles() = ' . print_r($roles, true));
            // Get dictionaryPermissions for the role at rowNumber
            $dictionaryPermissions = $roles[$rowNumber]->rolePermissions->dictionaryPermissions;
        } else {
            $role = $this->rolesRepository->getNewRole();
            $dictionaryPermissions = $role->rolePermissions->dictionaryPermissions;
        }

        $indexedDictionaryPermissions = [];
        foreach ($dictionaryPermissions as $dp) {
            // Convert associative array to indexed array, so DataTable's row container is indexed
            $indexedDictionaryPermissions[] = $dp;
        }

        $response = new Response(json_encode($indexedDictionaryPermissions, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/addRole', name: 'add-role', methods: ['POST'])]
    public function addRole(Request $request): CSProResponse {
        $this->denyAccessUnlessGranted(RolesVoter::ROLES_WRITE);
        $json = $request->getContent();
        try {
            $role = Role::fromJSON($json);
        } catch (\Exception $ex) {
            $this->logger->error('Failed adding role ' . $ex->getMessage());
            return CSProResponse::createError(500, 'add_role_error', "Failed to add role");
        }
        $roleName = $role->name;
        $roleName = empty($roleName) ? '' : htmlspecialchars(strip_tags($roleName), ENT_QUOTES, 'UTF-8');

        try {
            $result = $this->rolesRepository->addOrUpdateRole($role);
            if ($result === true)
                return new CSProResponse("Added $roleName", CSProResponse::HTTP_OK);
            else
                return new CSProResponse("Failed to add $roleName", CSProResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            $result['code'] = 500;
            $duplicateErrMsg = 'Integrity constraint violation: 1062';
            if (strpos($e->getMessage(), $duplicateErrMsg)) {
                $result['description'] = "Role name `$roleName` already in use";
            } else {
                $result['description'] = "Failed to add role: $roleName";
            }
            $response = new CSProResponse();
            $response->setError($result['code'], 'add_role_error', $result ['description']);
            return $response;
        }
    }

    #[Route('/editRole', name: 'edit-role', methods: ['POST'])]
    public function editRole(Request $request): CSProResponse {
        $this->denyAccessUnlessGranted(RolesVoter::ROLES_WRITE);

        $json = $request->getContent();
        $role = null;
        try {
            $role = Role::fromJSON($json);
        } catch (\Exception $ex) {
            $this->logger->error('Failed adding role ' . $ex->getMessage());
            return CSProResponse::createError(500, 'edit_role_error', "Failed to edit role");
        }

        $roleName = $role->name;
        $roleName = empty($roleName) ? '' : htmlspecialchars(strip_tags($roleName), ENT_QUOTES, 'UTF-8');
        
        if($role->id <= Role::BUILTIN_ROLE_MAX_ID){
            $response = new CSProResponse();
            $message = "Cannot edit built-in roles. Failed to save role: {$roleName}";
            $response->setError(500, 'save_role_error', $message);
            return $response;
        }

        try {
            $result = $this->rolesRepository->addOrUpdateRole($role,false);
            if ($result === true)
                return new CSProResponse("Saved permissions for $roleName", CSProResponse::HTTP_OK);
            else
                return new CSProResponse("Failed to save permissions for $roleName", CSProResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception) {
            $result['code'] = 500;
            $result['description'] = "Failed to save permissions for $roleName";
            $response = new CSProResponse();
            $response->setError($result['code'], 'save_permissions_error', $result ['description']);
            return $response;
        }
    }

    #[Route('/deleteRole', name: 'delete-role', methods: ['DELETE'])]
    public function DeleteRole(Request $request): Response {
        $this->denyAccessUnlessGranted(RolesVoter::ROLES_WRITE);

        $result = [];
        $roleName = $request->get('roleName');
        $roleName = empty($roleName) ? '' : htmlspecialchars(strip_tags($roleName), ENT_QUOTES, 'UTF-8');
        $roleId = $request->get('roleId');
        $roleId = empty($roleId)  ? 0 : (int)$request->get('roleId');

        if($roleId <= Role::BUILTIN_ROLE_MAX_ID){
            $response = new CSProResponse();
            $message = "Cannot delete built-in roles. Failed to delete role: {$roleName}";
            $response->setError(500, 'delete_role_error', $message);
            return $response;
        }
        $count = $this->rolesRepository->deleteRole($roleId, $roleName);

        if ($count) {
            $result['description'] = 'Deleted role ' . $roleName;
            $this->logger->debug($result['description']);
            $response = new Response(json_encode($result['description'], JSON_THROW_ON_ERROR), Response::HTTP_OK);
        } else {
            $result['description'] = 'Failed deleting role ' . $roleName;
            $response = new Response(json_encode($result['description'], JSON_THROW_ON_ERROR), Response::HTTP_NOT_FOUND);
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
