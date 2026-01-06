<?php
/**
 * @category TestEntities
 * @package  App\Tests\Entity
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     https://miw.etsisi.upm.es/ E.T.S. de Ingeniería de Sistemas Informáticos
 */
namespace App\Tests\Entity;

use App\Entity\Result;
use App\Entity\User;
use DateTime;
use Faker\Factory as FakerFactory;
use Faker\Generator as FakerGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('entities')]
#[CoversClass(Result::class)]
class ResultTest extends TestCase
{
    private static FakerGenerator $faker;
    private Result $result;
    private User $user;
    private DateTime $time;

    public static function setUpBeforeClass(): void
    {
        self::$faker = FakerFactory::create('es_ES');
    }

    protected function setUp(): void
    {
        // Stub de User
        $stubUser = $this->createStub(User::class);
        $stubUser->method('getId')->willReturn(self::$faker->numberBetween(1, 1000));
        $stubUser->method('getEmail')->willReturn(self::$faker->email());

        $this->user = $stubUser;

        // Fecha y hora del Result
        $this->time = self::$faker->dateTime();

        // Crear el Result con valores de Faker
        $this->result = new Result(
            self::$faker->numberBetween(1, 100),
            $this->user,
            $this->time
        );
    }

    public function testConstructor(): void
    {
        $this->assertSame($this->user, $this->result->getUser());
        $this->assertSame($this->time, $this->result->getTime());
        $this->assertIsInt($this->result->getResult());
    }

    public function testGetSetResult(): void
    {
        $newResult = self::$faker->numberBetween(1, 1000);

        $returned = $this->result->setResult($newResult);

        $this->assertSame($this->result, $returned);
        $this->assertSame($newResult, $this->result->getResult());
    }

    public function testGetSetTime(): void
    {
        $newTime = self::$faker->dateTime();

        $returned = $this->result->setTime($newTime);

        $this->assertSame($this->result, $returned);
        $this->assertSame($newTime, $this->result->getTime());
    }

    public function testGetSetUser(): void
    {
        $stubUser = $this->createStub(User::class);
        $stubUser->method('getId')->willReturn(self::$faker->numberBetween(1, 1000));
        $stubUser->method('getEmail')->willReturn(self::$faker->email());

        $returned = $this->result->setUser($stubUser);

        $this->assertSame($this->result, $returned);
        $this->assertSame($stubUser, $this->result->getUser());
    }

    public function testJsonSerialize(): void
    {
        // Forzar ID
        $reflection = new ReflectionClass($this->result);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($this->result, 1);

        $data = $this->result->jsonSerialize();

        $this->assertIsArray($data);

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('result', $data);
        $this->assertArrayHasKey('time', $data);
        $this->assertArrayHasKey('user', $data);

        $this->assertSame(1, $data['id']);
        $this->assertSame($this->result->getResult(), $data['result']);
        $this->assertSame(
            $this->time->format('Y-m-d H:i:s'),
            $data['time']
        );

        $this->assertIsArray($data['user']);
        $this->assertArrayHasKey('id', $data['user']);
        $this->assertArrayHasKey('email', $data['user']);
        $this->assertSame($this->user->getId(), $data['user']['id']);
        $this->assertSame($this->user->getEmail(), $data['user']['email']);
    }
}
