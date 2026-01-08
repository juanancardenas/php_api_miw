<?php

namespace App\Controller;

use App\Entity\Result;
use App\Entity\User;
use App\Utility\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{Exception\JsonException, Request, Response};
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Class ApiResultsQueryController
 *
 * @package App\Controller
 */
#[Route(
    path: ApiResultsQueryInterface::RUTA_API,
    name: 'api_results_'
)]
class ApiResultsQueryController extends AbstractController implements ApiResultsQueryInterface
{
    /**
     * Constructor de la clase que gestiona los comandos tipo Query (CGET, GET y OPTIONS) y que
     * inyecta el EntityManager para que usado en los métodos de implentación de operación HTTP
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $logger
    )
    {
    }

    /**
     * Implementación de la operación CGET
     * @param Request $request
     * @return Response
     * @see ApiResultsQueryInterface::cgetAction()
     */
    #[Route(
        path: ".{_format}/{sort?id}",
        name: 'cget',
        requirements: [
            'sort' => "id|user|result",
            '_format' => "json|xml"
        ],
        defaults: ['sort' => 'id', '_format' => 'json',],
        methods: [Request::METHOD_GET, Request::METHOD_HEAD],
    )]
    public function cgetAction(Request $request): Response
    {
        $format = Utils::getFormat($request);
        try {
            // Obtener usuario logado o HTTP Error 401 (Unauthorized) si no está logado
            $loggedUser = $this->checkUserAuthenticated('CGET');

            // Obtener todos los resultados del usuario
            $order = $request->attributes->get('sort', 'id');

            $results = $this->readAllResults(
                $order,
                $this->isGranted(self::ROLE_ADMIN),
                $loggedUser
            );

            // Validación de caché por ETag
            $etag = $this->checkCGetCache($request, $results);

            // Devolver resultado de la acción HTTP
            return $this->buildCGetResponse($results, $etag, $format, $request);

        } catch (HttpExceptionInterface $error) {
            return Utils::errorMessage(
                $error->getStatusCode(),
                $error->getMessage() ?: null,
                $format
            );
        }
    }

    /**
     * Implementación de la operación GET
     * @param Request $request
     * @param int $resultId
     * @return Response
     * @see ApiResultsQueryInterface::getAction()
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'get',
        requirements: [
            "resultId" => "\d+",
            '_format' => "json|xml"
        ],
        defaults: ['_format' => 'json'],
        methods: [Request::METHOD_GET, Request::METHOD_HEAD],
    )]
    public function getAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);
        try {
            // Obtener usuario logado o HTTP Error 401 (Unauthorized) si no está logado
            $loggedUser = $this->checkUserAuthenticated('GET');

            // Leer el resultado
            $result = $this->readResult($resultId, $loggedUser, $this->isGranted(self::ROLE_ADMIN));

            // Validación de caché por ETag
            $etag = $this->checkGetCache($request, $result);

            // Devolver resultado de la acción HTTP
            return $this->buildGetResponse($result, $etag, $format, $request);

        } catch (HttpExceptionInterface $error) {
            return Utils::errorMessage(
                $error->getStatusCode(),
                $error->getMessage() ?: null,
                $format
            );
        }
    }

    /**
     * Implementación de la operación OPTIONS
     * @throws JsonException
     * @see ApiResultsQueryInterface::optionsAction()
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'options',
        requirements: [
            'resultId' => "\d+",
            '_format' => "json|xml"
        ],
        defaults: ['resultId' => 0, '_format' => 'json'],
        methods: [Request::METHOD_OPTIONS],
    )]
    public function optionsAction(int|null $resultId): Response
    {
        $methods = $resultId !== 0
            ? [Request::METHOD_GET, Request::METHOD_PUT, Request::METHOD_DELETE]
            : [Request::METHOD_GET, Request::METHOD_POST];
        $methods[] = Request::METHOD_OPTIONS;

        return new Response(
            null,
            Response::HTTP_NO_CONTENT,
            [
                self::HEADER_ALLOW => implode(',', $methods),
                self::HEADER_CACHE_CONTROL => 'public, immutable'
            ]
        );
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
            throw new HttpException(Response::HTTP_UNAUTHORIZED, self::MSG_UNAUTHORIZED);
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
     * Lanza una query para obtener un resultado
     * @param int $resultId
     * @param User $loggedUser
     * @param bool $isAdmin
     * @return Result
     */
    private function readResult(int $resultId, User $loggedUser, bool $isAdmin): Result
    {
        $criteria = $isAdmin
            ? ['id' => $resultId]
            : ['id' => $resultId, 'user' => $loggedUser];

        // Se hace la consulta
        $result = $this->entityManager
            ->getRepository(Result::class)
            ->findOneBy($criteria);

        if (!$result instanceof Result) {
            // No hay resultado (404: Not found) - Evita antipatrón 403, no dar más información de la necesaria
            throw new HttpException(Response::HTTP_NOT_FOUND, self::MSG_NOT_FOUND);
        }

        return $result;
    }

    /**
     * Lanza una query para obtener todos los resultados del usuario logado
     * @param string $order
     * @param bool $isAdmin
     * @param User $loggedUser
     * @return array
     */
    private function readAllResults(string $order, bool $isAdmin, User $loggedUser): array
    {
        // Si el usuario es Admin tiene acceso a todos los resultados, si no, sólo a los suyos
        $criteria = $isAdmin
            ? []
            : ['user' => $loggedUser];

        // Se hace la consulta
        return $this->entityManager
            ->getRepository(Result::class)
            ->findBy(
                $criteria,
                [$order => 'ASC']
            );
    }

    /**
     * Validación de caché para un recurso por ETag
     * @param Request $request
     * @param Result $result
     * @return string
     */
    private function checkGetCache(Request $request, Result $result): string
    {
        $etag = Utils::generateResultETag($result);

        if ($request->headers->has('If-None-Match') && $etag === $request->headers->get('If-None-Match')) {
            throw new HttpException(
                Response::HTTP_NOT_MODIFIED,
                self::MSG_NOT_MODIFIED
            );
        }

        return $etag;
    }

    /**
     * Validación de caché para una colección de recursos por ETag
     * @param Request $request
     * @param array $results
     * @return string
     */
    private function checkCGetCache(Request $request, array $results): string
    {
        $etag = Utils::generateResultsCollectionETag($results);

        if ($request->headers->has('If-None-Match') && $etag === $request->headers->get('If-None-Match')) {
            throw new HttpException(
                Response::HTTP_NOT_MODIFIED,
                self::MSG_NOT_MODIFIED
            );
        }

        return $etag;
    }

    /**
     * Crea la respuesta de la operación GET, enviando un 200, el resultado y el etag.
     * @param Result $result
     * @param string $etag
     * @param string $format
     * @param Request $request
     * @return Response
     */
    private function buildGetResponse(Result $result, string $etag, string $format, Request $request): Response
    {
        return Utils::apiResponse(
            Response::HTTP_OK,
            ($request->isMethod(Request::METHOD_GET))
                ? [Result::RESULT_ATTR => $result]  // GET method
                : null,                             // HEAD method
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'private',
                self::HEADER_ETAG => $etag
            ]
        );
    }

    /**
     * Crea la respuesta de la operación CGET, enviando un 200, los resultados y el etag.
     * @param array $results
     * @param string $etag
     * @param string $format
     * @param Request $request
     * @return Response
     */
    private function buildCGetResponse(array $results, string $etag, string $format, Request $request): Response
    {
        // 200: OK
        return Utils::apiResponse(
            Response::HTTP_OK,
            ($request->isMethod(Request::METHOD_GET))
                ? [ 'results' => array_map(fn ($res) =>  ['result' => $res], $results)]  // GET method
                : null,                                                                  // HEAD method
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'private',
                self::HEADER_ETAG => $etag,
            ]
        );
    }
}
