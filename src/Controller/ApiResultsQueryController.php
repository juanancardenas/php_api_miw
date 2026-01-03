<?php

namespace App\Controller;

use App\Entity\Result;
use App\Entity\User;
use App\Utility\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{Exception\JsonException, Request, Response};
use Psr\Log\LoggerInterface;
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
    private const string HEADER_CACHE_CONTROL = 'Cache-Control';
    private const string HEADER_ETAG = 'ETag';
    private const string HEADER_ALLOW = 'Allow';

    /**
     * Constructor de la clase que gestiona los comandos tipo Query (CGET, GET y OPTIONS) y que
     * inyecta el EntityManager para que usado en los métodos de implentación de operación HTTP
     * @param EntityManagerInterface $entityManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Implementación de la operación CGET
     * @see ApiResultsQueryInterface::cgetAction()
     * @throws JsonException
     */
    #[Route(
        path: ".{_format}/{sort?id}",
        name: 'cget',
        requirements: [
            'sort' => "id|user|result",
            '_format' => "json|xml"
        ],
        defaults: [ 'sort' => 'id', '_format' => 'json', ],
        methods: [ Request::METHOD_GET, Request::METHOD_HEAD ],
    )]
    public function cgetAction(Request $request): Response
    {
        $format = Utils::getFormat($request);

        // Obtenemos usuario logado. Null si no está logado
        $loggedUser = $this->assertAuthenticated('CGET');
        if (!$loggedUser instanceof User) {
            // 401: No Authorized
            return Utils::errorMessage(
                Response::HTTP_UNAUTHORIZED,
                'UNAUTHORIZED: Invalid credentials.',
                $format
            );
        }

        // Ordenar por parámetro seleccinado
        $order = strval($request->attributes->get('sort'));

        // Si el usuario es Admin tiene acceso a todos los resultados, si no, sólo a los propios
        $criteria = $this->isGranted('ROLE_ADMIN')
            ? []
            : ['user' => $loggedUser];

        // Se hace la consulta
        $results = $this->entityManager
            ->getRepository(Result::class)
            ->findBy(
                $criteria,
                [$order => 'ASC']
            );

        // No hay resultados (404: Not found)
        if (empty($results)) {
            return Utils::errorMessage(Response::HTTP_NOT_FOUND, null, $format);
        }

        // Caching with ETag (304: Not Modified)
        $etag = md5((string) json_encode($results, JSON_THROW_ON_ERROR));
        if (($etags = $request->getETags()) && (in_array($etag, $etags) || in_array('*', $etags))) {
            return new Response()->setNotModified();
        }

        return Utils::apiResponse(
            Response::HTTP_OK,
            ($request->isMethod(Request::METHOD_GET))
                ? [ 'results' => array_map(
                      fn ($res) =>  ['result' => $res], $results
                     )]  // GET method
                : null,  // HEAD method
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'private',
                self::HEADER_ETAG => $etag,
            ]
        );
    }

    /**
     * Implementación de la operación GET
     * @see ApiResultsQueryInterface::getAction()
     * @throws JsonException
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'get',
        requirements: [
            "resultId" => "\d+",
            '_format' => "json|xml"
        ],
        defaults: [ '_format' => 'json' ],
        methods: [ Request::METHOD_GET, Request::METHOD_HEAD ],
    )]
    public function getAction(Request $request, int $resultId): Response
    {
        $format = Utils::getFormat($request);

        // Obtenemos usuario logado. Null si no está logado
        $loggedUser = $this->assertAuthenticated('GET');
        if (!$loggedUser instanceof User) {
            // 401: No Authorized
            return Utils::errorMessage(
                Response::HTTP_UNAUTHORIZED,
                'UNAUTHORIZED: Invalid credentials.',
                $format
            );
        }

        $criteria = $this->isGranted('ROLE_ADMIN')
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

        // Caching with ETag (304: Not Modified)
        $etag = md5(json_encode($result, JSON_THROW_ON_ERROR));
        if (($etags = $request->getETags()) && (in_array($etag, $etags) || in_array('*', $etags))) {
            return new Response()->setNotModified();
        }

        return Utils::apiResponse(
            Response::HTTP_OK,
            ($request->isMethod(Request::METHOD_GET))
                ? [ Result::RESULT_ATTR => $result ]  // GET method
                : null,                               // HEAD method
            $format,
            [
                self::HEADER_CACHE_CONTROL => 'private',
                self::HEADER_ETAG => $etag,
            ]
        );
    }

    /**
     * Implementación de la operación OPTIONS
     * @see ApiResultsQueryInterface::optionsAction()
     * @throws JsonException
     */
    #[Route(
        path: "/{resultId}.{_format}",
        name: 'options',
        requirements: [
            'resultId' => "\d+",
            '_format' => "json|xml"
        ],
        defaults: [ 'resultId' => 0, '_format' => 'json' ],
        methods: [ Request::METHOD_OPTIONS ],
    )]
    public function optionsAction(int|null $resultId): Response
    {
        $methods = $resultId !== 0
            ? [ Request::METHOD_GET, Request::METHOD_PUT, Request::METHOD_DELETE ]
            : [ Request::METHOD_GET, Request::METHOD_POST ];
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

    /**
     * Chequea si el usuario está autenticado y saca log
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
}
