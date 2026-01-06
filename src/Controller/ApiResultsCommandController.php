<?php

namespace App\Controller;

use Exception;
use App\Entity\{Result, User};
use App\Utility\Utils;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{InputBag, Request, Response};
use Symfony\Component\HttpKernel\Exception\{HttpException, HttpExceptionInterface};
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: ApiResultsQueryInterface::RUTA_API,
    name: 'api_results_'
)]
class ApiResultsCommandController extends AbstractController implements ApiResultsCommandInterface
{
    /**
     * Constructor de la clase que gestiona los comandos tipo Command (DELETE, POST y PUT) y que
     * inyecta el EntityManager para que sea usado en los métodos de implentación de operación HTTP
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Implementación de la operación DELETE
     * @param Request $request
     * @param int $resultId
     * @return Response
     * @see ApiResultsCommandInterface::deleteAction()
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'delete',
        requirements: [
            'resultId' => "\d+",
            '_format' => "json|xml"
        ],
        defaults: [ '_format' => null ],
        methods: [Request::METHOD_DELETE],
    )]
    public function deleteAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);
        try {
            // Obtener usuario logado o HTTP Error 401 (Unauthorized) si no está logado
            $loggedUser = $this->checkUserAuthenticated('DELETE');

            // Si no es Administrador, el resultado debe ser propiedad del usuario logado
            $result = $this->readResult($resultId, $loggedUser);

            // Borrado del resultado
            $this->entityManager->remove($result);
            $this->entityManager->flush();

            // Devolver resultado de la acción HTTP
            return Utils::apiResponse(Response::HTTP_NO_CONTENT);

        } catch (HttpExceptionInterface $error) {
            return Utils::errorMessage(
                $error->getStatusCode(),
                $error->getMessage() ?: null,
                $format
            );
        }
    }

    /**
     * Implementación de la operación POST - Crear un nuevo resultado
     * Esta acción será lanzada por cada usuario, de tal manera, que cada usuario pueda introducir
     * sus propios resultados.
     * @param Request $request
     * @return Response
     * @see ApiResultsCommandInterface::postAction()
     */
    #[Route(
        path: ".{_format}",
        name: 'post',
        requirements: [
            '_format' => "json|xml"
        ],
        defaults: [ '_format' => null ],
        methods: [Request::METHOD_POST],
    )]
    public function postAction(Request $request): Response
    {
        $format = Utils::getFormat($request);
        try {
            // Obtener usuario logado o HTTP Error 401 (Unauthorized) si no está logado
            $loggedUser = $this->checkUserAuthenticated('PUT');

            // Validación del los datos del payload
            $postData = $request->getPayload();
            $time = $this->validatePayload($postData, $this->isGranted(ApiResultsQueryInterface::ROLE_ADMIN));

            // Obtener el user: Si el usuario es un admin, podrá enviar el userId en el payload del POST indicando
            // un usuario distinto a sí mismo, si el usuario no es admin, no pondrá mandar el userId y el usuario
            // es él mismo. Permitiendo: Admin crear resultados de cualquier usuario y no-admin sólo los suyos.
            $user = $this->getUserPost($loggedUser, $postData, $this->isGranted(ApiResultsQueryInterface::ROLE_ADMIN));

            // Crear el resultado
            $result = new Result(
                $postData->get(Result::RESULT_ATTR),
                $user,
                $time,
            );

            // Insertar el resultado en la BD
            $this->entityManager->persist($result);
            $this->entityManager->flush();

            // Devolver resultado de la acción HTTP
            return $this->buildPostResponse($result, $format, $request);

        } catch (HttpExceptionInterface $error) {
            return Utils::errorMessage(
                $error->getStatusCode(),
                $error->getMessage() ?: null,
                $format
            );
        }
    }

    /**
     * Implementación de la operación PUT - Actualizar el resultado enviado por parámetro en la petición
     * Usuario administrador podrá cambiar cualquier Result pero los no-administrador sólo podrán cambiar los suyos.
     * El atributo user sólo puede ser modificado por el administrador, los atributos result y time pueden ser
     * modificador por todos los usuarios.
     * @param Request $request
     * @param int $resultId
     * @return Response
     * @see ApiResultsCommandInterface::putAction()
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'put',
        requirements: [
            'resultId' => "\d+",
            '_format' => "json|xml"
        ],
        defaults: [ '_format' => null ],
        methods: [Request::METHOD_PUT],
    )]
    public function putAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);
        try {
            // Obtener usuario logado o HTTP Error 401 (Unauthorized) si no está logado
            $loggedUser = $this->checkUserAuthenticated('PUT');

            // Si no es Administrador, el resultado debe ser propiedad del usuario logado
            $result = $this->readResult($resultId, $loggedUser);

            // Control de concurrencia por ETag
            $this->checkPutPrecondition($request, $result);

            // Validación del los datos del payload
            $postData = $request->getPayload();
            $time = $this->validatePayload($postData, $this->isGranted(ApiResultsQueryInterface::ROLE_ADMIN));

            // Actualizar el resultado
            $result->setTime($time);
            $result->setResult($postData->get(Result::RESULT_ATTR));
            // Sólo el administrador podría cambiar el usuario del resultado
            if ($this->isGranted(ApiResultsQueryInterface::ROLE_ADMIN)) {
                $result->setUser($this->getNewUser($result, $postData));
            }

            // Actualizar el Result en BD
            $this->entityManager->flush();

            // Generación del nuevo ETag
            $etag = Utils::generateResultETag($result);

            // Devolver resultado de la acción HTTP
            return $this->buildPutResponse($result, $format, $etag);

        } catch (HttpExceptionInterface $error) {
            return Utils::errorMessage(
                $error->getStatusCode(),
                $error->getMessage() ?: null,
                $format
            );
        }
    }

    /* Métodos auxiliares usados por las acciones */

    /**
     * Chequea si el usuario está autenticado y saca log de la acción en curso
     * @param string $action
     * @return User
     */
    private function checkUserAuthenticated(string $action): User
    {
        if (!$this->isGranted('IS_AUTHENTICATED_FULLY')) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED,self::MSG_UNAUTHORIZED);
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->logger->info('Authenticated user', [
            'action' => $action,
            'user_identifier' => $user->getUserIdentifier(),
            'roles' => $user->getRoles()
        ]);

        return $user;
    }

    /**
     * Chequeos generales del payload de los métodos PUT y POST
     * @param InputBag $postData
     * @param bool $isAdmin
     * @return DateTime
     */
    private function validatePayload(InputBag $postData, bool $isAdmin): DateTime {

        // Validar campos obligatorios
        $expectedFields = [
            Result::RESULT_ATTR,
            Result::TIME_ATTR
        ];
        foreach ($expectedFields as $field) {
            if (!$postData->has($field)) {
                throw new HttpException(Response::HTTP_BAD_REQUEST,self::MSG_MISSING_FIELDS);
            }
        }

        // Campos no permitidos: Id no puede ser modificado nunca y userid sólo por el Admin
        if ($postData->has('id') || (!$isAdmin && $postData->get(Result::USERID_ATTR))) {
            throw new HttpException(Response::HTTP_BAD_REQUEST,self::MSG_NOT_ALLOW);
        }

        // Validación result
        $resultValue = $postData->get(Result::RESULT_ATTR);
        if (!is_int($resultValue) || $resultValue < 0) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY,self::MSG_WRONG_RESULT);
        }

        // Validación time
        try {
            return new DateTime($postData->get(Result::TIME_ATTR));
        } catch (Exception) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY,self::MSG_WRONG_TIME);
        }
    }

    /**
     * Define el usuario a ser insertado en el result. Si el usuario es un admin, podrá enviar el userId
     * en el payload del POST indicando un usuario distinto a sí mismo, si el usuario no es admin, no pondrá
     * enviar userId y el usuario es él mismo.
     * Permitiendo: Admin crear resultados de cualquier usuario y no-admin sólo sus propios resultados.
     * @param User $loggedUser
     * @param InputBag $postData
     * @param bool $isAdmin
     * @return User
     */
    private function getUserPost(User $loggedUser, InputBag $postData, bool $isAdmin): User {

        // Si el usuario es no-administrador o el payload no contiene un userId, se devuelve el usuario logado.
        if ((!$isAdmin) || (!$postData->has(Result::USERID_ATTR))) {
            $user = $this->entityManager
                ->getRepository(User::class)
                ->find($loggedUser->getId());
        } else {
            // Si el usuario es administrador y el payload contiene un userId, se devuelve el usuario del payload.
            $user = $this->entityManager
                ->getRepository(User::class)
                ->find($postData->get(Result::USERID_ATTR));
        }

        if (!$user instanceof User) {
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY,self::MSG_WRONG_USERID);
        }

        return $user;
    }

    /**
     * Recupera de la BD el result que se pretende actualizar desde PUT o borrar desde DELETE
     * @param int $resultId
     * @param User $loggedUser
     * @return Result
     */
    private function readResult(int $resultId, User $loggedUser): Result
    {
        $criteria = $this->isGranted(ApiResultsQueryInterface::ROLE_ADMIN)
            ? ['id' => $resultId]
            : ['id' => $resultId, 'user' => $loggedUser];

        $result = $this->entityManager
            ->getRepository(Result::class)
            ->findOneBy($criteria);

        if (!$result instanceof Result) {
            // No hay resultado (404: Not found) - Evita antipatrón 403, no dar más información de la necesaria
            throw new HttpException(Response::HTTP_NOT_FOUND,self::MSG_NOT_FOUND);
        }

        return $result;
    }

    /**
     * Validación de cambios en el recurso por ETag
     * @param Request $request
     * @param Result $result
     * @return void
     */
    private function checkPutPrecondition(Request $request, Result $result): void
    {
        if (!$request->headers->has('If-Match')) {
            throw new HttpException(
                Response::HTTP_PRECONDITION_FAILED,
                self::MSG_FAILED_ETAG
            );
        }

        $etag = Utils::generateResultETag($result);

        if ($etag !== $request->headers->get('If-Match')) {
            throw new HttpException(
                Response::HTTP_PRECONDITION_FAILED,
                self::MSG_FAILED_ETAG
            );
        }
    }

    /**
     * Obtiene el usuario de la petición PUT (Sólo para Admin)
     * @param Result $result
     * @param InputBag $postData
     * @return User
     */
    private function getNewUser(Result $result, InputBag $postData): User {

        if ($result->getUser() !== $postData->get(Result::USER_ATTR)) {
            // Buscar el usuario indicado en la request
            $user = $this->entityManager
                ->getRepository(User::class)
                ->find($postData->get(Result::USERID_ATTR));

            if ($user instanceof User) {
                return $user;
            } else {
                throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY,self::MSG_WRONG_USERID);
            }
        } else {
            return $result->getUser();
        }
    }

    /**
     * Crea la respuesta de la operación PUT, enviando un 209 y el nuevo ETag.
     * @param Result $result
     * @param string $format
     * @param string $etag
     * @return Response
     */
    private function buildPutResponse(Result $result, string $format, string $etag): Response
    {
        // 209: Content Returned
        return Utils::apiResponse(
            209,
            [$result],
            $format,
            [
                'ETag' => $etag,
                'Cache-Control' => 'private',
            ]
        );
    }

    /**
     * Crea la respuesta de la operación POST, enviando un 201 y el result creado.
     * @param Result $result
     * @param string $format
     * @param Request $request
     * @return Response
     */
    private function buildPostResponse(Result $result, string $format, Request $request): Response
    {
        // 201: Created
        return Utils::apiResponse(
            Response::HTTP_CREATED,
            [ $result ],
            $format,
            [
                'Location' => $request->getScheme() . '://' . $request->getHttpHost() .
                                        ApiResultsQueryInterface::RUTA_API . '/' . $result->getId()
            ]
        );
    }
}
