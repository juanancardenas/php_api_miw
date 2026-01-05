<?php

namespace App\Controller;

use App\Entity\Result;
use App\Entity\User;
use App\Utility\Utils;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{InputBag, Request, Response};
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: ApiResultsQueryInterface::RUTA_API,
    name: 'api_results_'
)]
class ApiResultsCommandController extends AbstractController implements ApiResultsCommandInterface
{
    private const string ROLE_ADMIN = 'ROLE_ADMIN';
    private const string UNAUTHORIZED = 'UNAUTHORIZED: Invalid credentials.';

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

        // Obtenemos usuario logado. Null si no está logado
        $loggedUser = $this->assertAuthenticated('DELETE');
        if (!$loggedUser instanceof User) {
            // 401: No Authorized
            return Utils::errorMessage(
                Response::HTTP_UNAUTHORIZED,
                self::UNAUTHORIZED,
                $format
            );
        }

        // Si no es Administrador, el resultado debe ser propiedad del usuario logado
        $criteria = $this->isGranted(self::ROLE_ADMIN)
            ? ['id' => $resultId]
            : ['id' => $resultId, 'user' => $loggedUser];

        // Se hace la consulta
        $result = $this->entityManager
            ->getRepository(Result::class)
            ->findOneBy($criteria);

        // No hay resultado (404: Not found)
        if (!$result instanceof Result) {
            return Utils::errorMessage(Response::HTTP_NOT_FOUND, null, $format);
        }

        $this->entityManager->remove($result);
        $this->entityManager->flush();

        return Utils::apiResponse(Response::HTTP_NO_CONTENT);
    }

    /**
     * Implementación de la operación POST - Crear un nuevo resultado
     * Esta acción será lanzada por cada usuario, de tal manera, que cada usuario pueda introducir
     * sus propios resultados.
     * @param Request $request
     * @return Response
     * @throws \DateMalformedStringException
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

        // Obtenemos usuario logado. Null si no está logado
        $loggedUser = $this->assertAuthenticated('POST');
        if (!$loggedUser instanceof User) {
            // 401: No Authorized
            return Utils::errorMessage(
                Response::HTTP_UNAUTHORIZED,
                self::UNAUTHORIZED,
                $format
            );
        }

        // Validación del los datos del payload
        $postData = $request->getPayload();
        $responseError = $this->validatePostInput($postData, $format);
        if ($responseError instanceof Response) {
            return $responseError;
        }

        $time = new \DateTime($postData->get(Result::TIME_ATTR));

        $user = $this->entityManager
            ->getRepository(User::class)
            ->find($loggedUser->getId()); // No hace falta chequear user ya que userLogged ya lo ha sido

        // Crear el resultado
        $result = new Result(
            $postData->get(Result::RESULT_ATTR),
            $user,
            $time,
        );

        $this->entityManager->persist($result);
        $this->entityManager->flush();

        // 201: Created
        return Utils::apiResponse(
            Response::HTTP_CREATED,
            //[ Result::ID_ATTR => $result->getId() ],
            [ $result ],
            $format,
            [
                'Location' => $request->getScheme() . '://' . $request->getHttpHost() .
                    ApiResultsQueryInterface::RUTA_API . '/' . $result->getId(),
            ]
        );
    }

    /**
     * Implementación de la operación PUT - Actualizar el resultado enviado por parámetro en la petición
     * Esta acción permitirá cambiar los atributos Result y Time, no se podrá cambiar el atributo User, el usuario
     * administrador podrá cambiar cualquier Result pero el usuario no-administrador sólo podrá cambiar los suyos.
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

        // Obtenemos usuario logado. Null si no está logado
        $loggedUser = $this->assertAuthenticated('PUT');
        if (!$loggedUser instanceof User) {
            // 401: No Authorized
            return Utils::errorMessage(
                Response::HTTP_UNAUTHORIZED,
                self::UNAUTHORIZED,
                $format
            );
        }

        // Si no es Administrador, el resultado debe ser propiedad del usuario logado
        $criteria = $this->isGranted(self::ROLE_ADMIN)
            ? ['id' => $resultId]
            : ['id' => $resultId, 'user' => $loggedUser];

        // Se hace la consulta
        $result = $this->entityManager
            ->getRepository(Result::class)
            ->findOneBy($criteria);

        // No hay resultado (404: Not found)
        if (!$result instanceof Result) {
            return Utils::errorMessage(Response::HTTP_NOT_FOUND, null, $format);
        }

        // Chequear que no haya cambiado el ETag
        $etag = md5(json_encode($result, JSON_THROW_ON_ERROR));
        if (!$request->headers->has('If-Match') || $etag != $request->headers->get('If-Match')) {
            // 412: Precondition failed
            return Utils::errorMessage(
                Response::HTTP_PRECONDITION_FAILED,
                'PRECONDITION FAILED: one or more conditions given evaluated to false',
                $format
            );
        }

        // Validación del los datos del payload
        $postData = $request->getPayload();
        $timeOrError = $this->validatePutInput($postData, $format);
        if ($timeOrError instanceof Response) {
            return $timeOrError; // Error de validación es enviado
        }

        // Actualizar el resultado
        $result->setResult($postData->get(Result::RESULT_ATTR));
        $result->setTime($timeOrError);

        $this->entityManager->flush();

        // ETag del result después de actualizarse
        $newEtag = md5(json_encode($result, JSON_THROW_ON_ERROR));

        // 209: Content Returned
        return Utils::apiResponse(
            209, [ $result ],
            $format,
            [
                'ETag' => $newEtag,
                'Cache-Control' => 'private',
            ]
        );
    }

    /**
     * Chequea si el usuario está autenticado y saca log
     * @param string $action
     * @return User|null
     */
    private function assertAuthenticated(string $action): ?User
    {
        if (!$this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return null;
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
     * Chequea si el payload del POST es correcto
     * @param InputBag $postData
     * @param string $format
     * @return Response|null
     */
    private function validatePostInput(InputBag $postData, string $format): ?Response {
        // Campos obligatorios
        if (!$postData->has(Result::RESULT_ATTR) || !$postData->has(Result::TIME_ATTR)) {
            return Utils::errorMessage(Response::HTTP_UNPROCESSABLE_ENTITY, null, $format);
        }

        // Campos no permitidos
        if ($postData->has('user') || $postData->has('id')) {
            return Utils::errorMessage(Response::HTTP_BAD_REQUEST, null, $format);
        }

        // result
        $resultValue = $postData->get(Result::RESULT_ATTR);
        if (!is_int($resultValue) || $resultValue < 0) {
            return Utils::errorMessage(Response::HTTP_UNPROCESSABLE_ENTITY, null, $format);
        }

        // time
        try {
            new \DateTime($postData->get(Result::TIME_ATTR));
        } catch (\Exception) {
            return Utils::errorMessage(Response::HTTP_UNPROCESSABLE_ENTITY, null, $format);
        }

        return null;
    }

    /**
     * Chequea si el payload del PUT es correcto
     * @param InputBag $postData
     * @param string $format
     * @return DateTime|Response
     */
    private function validatePutInput(InputBag $postData, string $format): DateTime|Response {

        $expectedFields = [
            Result::RESULT_ATTR,
            Result::TIME_ATTR
        ];

        // Campos obligatorios
        foreach ($expectedFields as $field) {
            if (!$postData->has($field)) {
                return Utils::errorMessage(Response::HTTP_BAD_REQUEST, null, $format);
            }
        }

        // Campos no permitidos
        if ($postData->has('user') || $postData->has('id')) {
            return Utils::errorMessage(Response::HTTP_BAD_REQUEST, null, $format);
        }

        // Validación result
        $resultValue = $postData->get(Result::RESULT_ATTR);
        if (!is_int($resultValue) || $resultValue < 0) {
            return Utils::errorMessage(Response::HTTP_UNPROCESSABLE_ENTITY, null, $format);
        }

        // Validación time
        try {
            return new \DateTime($postData->get(Result::TIME_ATTR));
        } catch (\Exception) {
            return Utils::errorMessage(Response::HTTP_UNPROCESSABLE_ENTITY, null, $format);
        }
    }
}
