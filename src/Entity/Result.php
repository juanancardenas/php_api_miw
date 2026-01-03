<?php
/**
 * src/Entity/Result.php
 *
 * @category Entities
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     https://www.etsisi.upm.es/ ETS de Ingeniería de Sistemas Informáticos
 */
namespace App\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;

#[ORM\Entity]
#[ORM\Table(
    name: 'results',
    indexes: [ 'name' => 'FK_USER_ID_idx', 'columns' => [ 'user_id' ] ]
)]
class Result implements JsonSerializable
{
    public final const string RESULT_ATTR = 'result';

    #[ORM\Column(
        name: 'id',
        type: 'integer',
        nullable: false
    )]
    #[ORM\Id, ORM\GeneratedValue(strategy: "IDENTITY")]
    protected int $id;

    #[ORM\Column(
        name: 'result',
        type: 'integer',
        nullable: false
    )]
    protected int $result;

    #[ORM\ManyToOne(targetEntity: 'User')]
    #[ORM\JoinColumn(
        name: 'user_id',
        referencedColumnName: 'id',
        onDelete: 'CASCADE'
    )]
    protected User $user;

    #[ORM\Column(
        name: 'time',
        type: 'datetime',
        nullable: false
    )]
    protected DateTime $time;

    public function __construct(int $result, User $user, ?DateTime $time = null)
    {
        $this->result = $result;
        $this->user   = $user;
        $this->time   = $time;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResult(): int
    {
        return $this->result;
    }

    public function setResult(int $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function getTime(): DateTime
    {
        return $this->time;
    }

    public function setTime(DateTime $time): self
    {
        $this->time = $time;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id'     => $this->id,
            'result' => $this->result,
            'time'   => $this->time->format('Y-m-d H:i:s'),
            'user'   => [
                'id'    => $this->user->getId(),
                'email' => $this->user->getEmail(),
            ],
        ];
    }
}
