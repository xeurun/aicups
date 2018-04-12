<?php

/**
 * Типы действий
 */
interface ActionInterface
{
    /** @var string  */
    public const NONE = 'none';
    /** @var string  */
    public const MOVE_TO_POINT = 'mtpoint';
    /** @var string  */
    public const MOVE_TO_FOOD = 'mtfood';
    /** @var string  */
    public const MOVE_TO_PLAYER = 'mtpplayer';
    /** @var string  */
    public const MOVE_FROM_PLAYER = 'mfpplayer';
    /** @var string  */
    public const MOVE_FROM_VIRUS = 'mfvirus';
}

/**
 * Области карты
 */
interface SquareInterface
{
    /** @var string  */
    public const FIRST = 'I';
    /** @var string  */
    public const SECOND = 'II';
    /** @var string  */
    public const THIRD = 'III';
    /** @var string  */
    public const FOURTH = 'IV';
}

/**
 * Типы объектов
 */
interface ObjectTypeInterface
{
    /** @var string  */
    public const FOOD = 'F';
    /** @var string  */
    public const PLAYER = 'P';
    /** @var string  */
    public const VIRUS = 'V';
    /** @var string  */
    public const EJECTION = 'E';
}

class Strategy
{
    /** @var int|boolean через какое количество тиков выполнять сброс действия */
    public const RESET_ACTION_TICK_COUNT = false;
    /** @var int на сколько единиц должен быть больше наш вес чтобы мы пошли в атаку */
    public const SAFE_ATTACK_RADIUS = 3;

    /** @var string текущее действие */
    private $currentAction = ActionInterface::NONE;
    /** @var object основной конфиг */
    private $config;
    /** @var string идентификатор игрока в таргете */
    private $targetId = 0;
    /** @var string координата движения по X */
    private $nextX = 0;
    /** @var string координата движения по Y */
    private $nextY = 0;
    /** @var string текущий тик */
    private $currentTick = 0;
    /** @var string следующий тик сброса */
    private $nextResetTick = self::RESET_ACTION_TICK_COUNT;
    /** @var string|false писать отладочные сообщений в файл */
    private $debugFile = false;

    /**
     *
     */
    public function run()
    {
        if ($this->debugFile) {
            // Очищаем файл
            file_put_contents($this->debugFile, '');
        }

        $line = trim(fgets(STDIN));
        $currentConfig = json_decode($line);

        while (1) {
            $line = trim(fgets(STDIN));
            $parsed = json_decode($line);
            $command = $this->onTick($parsed, $currentConfig);
            print json_encode($command) . "\n";
        }
    }

    /**
     * @param array $parsed
     *    $parsed['Mine'] - fragments
     *    $parsed['Objects'] - visible objects
     */
    public function onTick(array $parsed, array $config): array
    {
        $split = false;
        // Считаем тики
        $this->currentTick++;

        // Проверяем свои фрагменты
        $mines = $parsed->Mine;
        if (!empty($mines)) {
            // Если есть хоть один

            // Сеттим/обновляем конфиг
            $this->config = $config;

            // Определяем главный фрагмент (самый мелкий)
            $commonMine = null;
            foreach ($mines as $mine) {
                if ($commonMine === null || $commonMine->M > $mine->M) {
                    $commonMine = $mine;
                }
            }

            if ($this->nextResetTick && $this->currentTick > $this->nextResetTick) {
                // Если указан тик сброса действия, сбрасываем действие
                $this->currentAction = ActionInterface::NONE;
                $this->nextResetTick += self::RESET_ACTION_TICK_COUNT;
            } else {
                if ($this->getVectorDistanceToPoint($commonMine, $this->nextX, $this->nextY) < $commonMine->R) {
                    // Сбрасываем действие если мы у точки
                    $this->currentAction = ActionInterface::NONE;
                }
            }

            // Проверяем конкретные стратегии
            if (
                \in_array(
                    $this->currentAction,
                    [ActionInterface::MOVE_TO_POINT, ActionInterface::MOVE_TO_FOOD, ActionInterface::NONE],
                    true
                )
            ) {
                // Ищем еду если все спокойно
                $this->checkFood($commonMine, $parsed);
            }
            $this->checkVirus($commonMine, $parsed);
            $player = $this->checkPlayer($commonMine, $parsed);

            if (
                $commonMine->M > 140
                && (
                    !\in_array(
                        $this->currentAction,
                        [ActionInterface::MOVE_FROM_PLAYER, ActionInterface::MOVE_TO_FOOD],
                        true
                    )
                    || (
                        $this->currentAction === ActionInterface::MOVE_TO_PLAYER
                        && $player !== null
                        && ($commonMine->R * 2 + static::SAFE_ATTACK_RADIUS > $player->R)
                    )
                )
            ) {
                // Если мы выросли и нет опасности, делимся
                $split = true;
            }

            if ($this->currentAction === ActionInterface::NONE) {
                // Если сейчас не двигаемся, пересчитываем точки
                $this->moveToSquare($this->getRandomSquareBySquare($this->getObjectSquare($commonMine)));
                $this->currentAction = ActionInterface::MOVE_TO_POINT;
            }

            $this->debug(__FUNCTION__, 'new action - ' . $this->currentAction);

            // Двигаемся к точке
            return ['X' => $this->nextX, 'Y' => $this->nextY, 'Split' => $split];
        }

        // Умер
        return ['X' => 0, 'Y' => 0, 'Debug' => 'Died'];
    }

    /**
     * Дистанция между точками
     *
     * @param object $vector
     * @param int $x
     * @param int $y
     */
    private function getVectorDistanceToPoint($vector, $x, $y): float
    {
        return sqrt((($x - $vector->X) ** 2) + (($y - $vector->Y) ** 2));
    }

    /**
     * @param object $commonMine
     * @param object $parsed
     */
    private function checkFood($commonMine, $parsed): void
    {
        $food = $this->findObject($parsed->Objects, ObjectTypeInterface::FOOD, $commonMine);
        if (null !== $food) {
            // Если нашли еду, идем к ней
            $this->nextX = $food->X;
            $this->nextY = $food->Y;
            $this->changeAction(ActionInterface::MOVE_TO_FOOD);
        }
    }

    /**
     * Находим объект
     *
     * @param object[] $objects
     * @param string $type
     * @param object|null $currentObject - если указан, возвращается ближайший объект
     *
     * @return object
     */
    private function findObject($objects, $type, $currentObject = null)
    {
        $object = null;

        for ($i = 0, $size = count($objects); $i < $size; ++$i) {
            if ($objects[$i]->T === $type) {
                if (
                    $currentObject === null
                    || $object === null
                    || $this->getVectorDistance(
                        $currentObject,
                        $objects[$i]
                    )
                    < $this->getVectorDistance($currentObject, $object)
                ) {
                    $object = $objects[$i];
                    if ($currentObject === null) {
                        break;
                    }
                }
            }
        }

        return $object;
    }

    /**
     * Дистанция между точками
     *
     * @param object $first
     * @param object $second
     */
    private function getVectorDistance($first, $second): float
    {
        return sqrt((($second->X - $first->X) ** 2) + (($second->Y - $first->Y) ** 2));
    }

    /**
     * Смена действия
     *
     * @param string $action
     *
     * @return void
     */
    private function changeAction($action): void
    {
        $this->currentAction = $action;
    }

    /**
     * @param object $commonMine
     * @param object $parsed
     */
    private function checkVirus($commonMine, $parsed): void
    {
        $virus = $this->findObject($parsed->Objects, ObjectTypeInterface::VIRUS, $commonMine);
        if (null !== $virus) {
            if (
                $commonMine->M
                > $virus->M
                && (
                    $this->currentAction === ActionInterface::NONE
                    || $this->targetId !== $virus->Id
                )
                && (
                    $this->getVectorDistance(
                        $commonMine,
                        $virus
                    )
                    < $commonMine->R
                )
            ) {
                // Если видим что ближайший вирус больше нас и в опасной дистанции(+ наш радиус), смещаемся
                $this->debug(__FUNCTION__,
                    'big virus - ' . $commonMine->M . ' > ' . $virus->M . '(' . $virus->X . ':' . $virus->Y . ')');
                $this->moveToSquare($this->getRandomSquareBySquare($this->getObjectSquare($virus)));
                $this->targetId = $virus->Id;
                $this->changeAction(ActionInterface::MOVE_FROM_VIRUS);
            }
        }
    }

    /**
     * @param string $method
     * @param string $message
     */
    private function debug($method, $message): void
    {
        if ($this->debugFile) {
            file_put_contents($this->debugFile, $this->currentTick . "($method): $message" . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * Движение к квадрату
     */
    private function moveToSquare(string $square): void
    {
        $halfGameWidth = $this->config->GAME_WIDTH / 2;
        $halfGameHeight = $this->config->GAME_HEIGHT / 2;

        $this->debug(__FUNCTION__, 'Move to - ' . $square);

        switch ($square) {
            case SquareInterface::FIRST:
                $this->nextX = $halfGameWidth * 0.5;
                $this->nextY = $halfGameHeight * 0.5;
                break;
            case SquareInterface::SECOND:
                $this->nextX = $halfGameWidth * 1.5;
                $this->nextY = $halfGameHeight * 0.5;
                break;
            case SquareInterface::THIRD:
                $this->nextX = $halfGameWidth * 0.5;
                $this->nextY = $halfGameHeight * 1.5;
                break;
            case SquareInterface::FOURTH:
                $this->nextX = $halfGameWidth * 1.5;
                $this->nextY = $halfGameHeight * 1.5;
                break;
            default:
                break;
        }
    }

    /**
     * Получить рандомное направление движения относительно текущего квадрата
     *
     * @param string $square указанный квадрат также убирается из рандома
     * @param string|array|null $except если указан дополнительный квадрат он также убирается из рандома
     */
    private function getRandomSquareBySquare($square, $except = null): string
    {
        $fullSet = [SquareInterface::FIRST, SquareInterface::SECOND, SquareInterface::THIRD, SquareInterface::FOURTH];
        unset($fullSet[array_search($square, $fullSet, true)]);
        $this->debug(__FUNCTION__, 'unset current square - ' . $square);
        if (null !== $except) {
            $except = \is_array($except) ? $except : [$except];
            foreach ($except as $exc) {
                $indx = array_search($exc, $fullSet, true);
                if ($indx !== false) {
                    $this->debug(__FUNCTION__, 'unset square - ' . $exc);
                    unset($fullSet[$indx]);
                }
            }
        }

        $randomSquareIndx = array_rand($fullSet);
        $this->debug(
            __FUNCTION__,
            'set - ' . var_export($fullSet, true) . ', random index - ' . $randomSquareIndx
        );

        return $fullSet[$randomSquareIndx];
    }

    /**
     * Квадрат местонахождения объекта
     *
     * @param object $object
     */
    private function getObjectSquare($object): string
    {
        $square = null;
        $halfGameWidth = $this->config->GAME_WIDTH / 2;
        $halfGameHeight = $this->config->GAME_HEIGHT / 2;

        if ($object->X < $halfGameWidth && $object->Y < $halfGameHeight) {
            $square = SquareInterface::FIRST;
        }
        if ($object->X > $halfGameWidth && $object->Y < $halfGameHeight) {
            $square = SquareInterface::SECOND;
        }
        if ($object->X < $halfGameWidth && $object->Y > $halfGameHeight) {
            $square = SquareInterface::THIRD;
        }
        if ($object->X > $halfGameWidth && $object->Y > $halfGameHeight) {
            $square = SquareInterface::FOURTH;
        }

        return $square;
    }

    /**
     * @param object $commonMine
     * @param object $parsed
     *
     * @return object
     */
    private function checkPlayer($commonMine, $parsed)
    {
        $player = $this->findObject($parsed->Objects, ObjectTypeInterface::PLAYER, $commonMine);
        if (null !== $player) {
            // Определяем направление движения
            if ($commonMine->R > ($player->R + static::SAFE_ATTACK_RADIUS)) {
                // Догоняем
                $this->nextX = $player->X;
                $this->nextY = $player->Y;

                $this->changeAction(ActionInterface::MOVE_TO_PLAYER);
            } else {
                if ($player->R > $commonMine->R && ($this->targetId !== $player->Id || $this->currentAction === ActionInterface::NONE)) {
                    $this->debug(__FUNCTION__, 'old enemy - ' . $this->targetId . ', new enemy - ' . $player->Id);

                    $commonMineSquare = $this->getObjectSquare($commonMine);
                    $playerSquare = $this->getObjectSquare($player);
                    $angle = $this->getAngle($commonMine, $player);

                    $this->debug(__FUNCTION__, 'enemy angle - ' . $angle);
                    $this->debug(__FUNCTION__, 'enemy square - ' . $playerSquare);

                    $except = [];
                    switch ($commonMineSquare) {
                        case SquareInterface::FIRST:
                            if ($angle > 0 && $angle < 90) {
                                // Точно нельзя
                                $except[] = SquareInterface::SECOND;
                                if ($angle > 60 && $angle < 90) {
                                    $except[] = SquareInterface::FOURTH;
                                }
                            } else {
                                if ($angle > 90 && $angle < 180) {
                                    // Точно нельзя
                                    $except[] = SquareInterface::FOURTH;
                                    if ($angle > 120 && $angle < 140) {
                                        $except[] = SquareInterface::SECOND;
                                    }
                                    if ($angle > 160 && $angle < 180) {
                                        $except[] = SquareInterface::THIRD;
                                    }
                                } else {
                                    if ($angle > 180 && $angle < 270) {
                                        // Точно нельзя
                                        $except[] = SquareInterface::THIRD;
                                    }
                                }
                            }
                            break;
                        case SquareInterface::SECOND:
                            if ($angle > 90 && $angle < 180) {
                                // Точно нельзя
                                $except[] = SquareInterface::FOURTH;
                                if ($angle > 160 && $angle < 180) {
                                    $except[] = SquareInterface::THIRD;
                                }
                            }
                            if ($angle > 180 && $angle < 270) {
                                // Точно нельзя
                                $except[] = SquareInterface::THIRD;
                                if ($angle > 250 && $angle < 270) {
                                    $except[] = SquareInterface::FIRST;
                                }
                                if ($angle > 180 && $angle < 200) {
                                    $except[] = SquareInterface::FOURTH;
                                }
                            }
                            if ($angle > 270 && $angle < 360) {
                                // Точно нельзя
                                $except[] = SquareInterface::FIRST;
                            }
                            break;
                        case SquareInterface::THIRD:
                            if ($angle > 0 && $angle < 90) {
                                // Точно нельзя
                                $except[] = SquareInterface::SECOND;
                                if ($angle > 0 && $angle < 20) {
                                    $except[] = SquareInterface::FIRST;
                                }
                                if ($angle > 60 && $angle < 90) {
                                    $except[] = SquareInterface::FOURTH;
                                }
                            }
                            if ($angle > 90 && $angle < 180) {
                                if ($angle > 90 && $angle < 110) {
                                    $except[] = SquareInterface::FOURTH;
                                }
                            }
                            if ($angle > 270 && $angle < 360) {
                                $except[] = SquareInterface::FIRST;
                            }
                            break;
                        case SquareInterface::FOURTH:
                            if ($angle > 0 && $angle < 90) {
                                if ($angle > 0 && $angle < 20) {
                                    $except[] = SquareInterface::SECOND;
                                }
                            }
                            if ($angle > 180 && $angle < 270) {
                                if ($angle > 250 && $angle < 270) {
                                    $except[] = SquareInterface::THIRD;
                                }
                            }
                            if ($angle > 270 && $angle < 360) {
                                $except[] = SquareInterface::FIRST;
                                if ($angle > 270 && $angle < 290) {
                                    $except[] = SquareInterface::THIRD;
                                }
                                if ($angle > 340 && $angle < 360) {
                                    $except[] = SquareInterface::SECOND;
                                }
                            }
                            break;
                        default:
                            break;
                    }

                    $this->moveToSquare($this->getRandomSquareBySquare($playerSquare, $except));
                    $this->targetId = $player->Id;
                    $this->changeAction(ActionInterface::MOVE_FROM_PLAYER);
                }
            }
        }

        return $player;
    }

    /**
     * @param object $center
     * @param object $point
     */
    private function getAngle($center, $point): float
    {
        $x = $point->X - $center->X;
        $y = $point->Y - $center->Y;
        if ($x === 0) {
            return ($y > 0) ? 180 : 0;
        }

        $a = atan($y / $x) * 180 / M_PI;

        return ($x > 0) ? $a + 90 : $a + 270;
    }
}

$strategy = new Strategy();
$strategy->run();
