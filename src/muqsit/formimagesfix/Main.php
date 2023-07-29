<?php
declare(strict_types=1);

namespace muqsit\formimagesfix;

use pocketmine\entity\Attribute;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\CancelTaskException;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\SingletonTrait;

final class Main extends PluginBase implements Listener{
    use SingletonTrait;
    public const UPDATE_TITLE = 0;
    public const UPDATE_MESSAGE = 1;
    public const UPDATE_ATTRIBUTE = 2;

    protected int $update_mode = self::UPDATE_ATTRIBUTE;

    /** @var int[] */
    private array $cachedForms = [];
    private array $currentForms = [];

    protected function onEnable() : void{
        self::setInstance($this);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function getUpdateMode() : int{
        return $this->update_mode;
    }

    public function setUpdateMode(int $update_mode) : void{
        $this->update_mode = match($update_mode) {
            self::UPDATE_TITLE => self::UPDATE_TITLE,
            self::UPDATE_MESSAGE => self::UPDATE_MESSAGE,
            default => self::UPDATE_ATTRIBUTE
        };
    }

    private function onPacketSend(Player $player, int $form_id) : void{
        $pk = NetworkStackLatencyPacket::request(time()); // time() -> 0?
        $player->getNetworkSession()->sendDataPacket($pk);
        $this->cachedForms[$player->getId()][$form_id] = false;
        $this->currentForms[$player->getId()] = $form_id;
    }

    private function calculateNextFormId(Player $player) : int{
        $pid = $player->getId();
        $current = $this->currentForms[$pid];
        while ($current >= 0) {
            if (isset($this->cachedForms[$pid][--$current])) {
                return $current;
            }
        }
        return -1;
    }

    public static function sendUpdate(Player $player, int $type = self::UPDATE_ATTRIBUTE) : void{
        if ($type === self::UPDATE_MESSAGE) {
            $player->sendMessage("");
        } elseif ($type === self::UPDATE_TITLE) {
            $player->sendTitle("", "", 0, 0, 0); //send an empty title, which will quickly disappear? Instant?
        } else {
            $attr = $player->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL);
            /** @noinspection NullPointerExceptionInspection */
            $entries = [new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), [])];
            $player->getNetworkSession()->sendDataPacket(UpdateAttributesPacket::create($player->getId(), $entries, 0));
        }
    }

    public function onDataPacketReceive(DataPacketReceiveEvent $event) : void{
        $player = $event->getOrigin()->getPlayer();
        if ($player === null || !$player->isConnected() || !$player->isOnline()) {
            return;
        }
        $pid = $player->getId();
        $packet = $event->getPacket();
        if ($packet instanceof ModalFormResponsePacket) {
            $this->cachedForms[$pid][$packet->formId] = true;
            $next = $this->calculateNextFormId($player);
            if ($next !== -1) {
                $this->currentForms[$player->getId()] = $next;
            }
        }
        if (($packet instanceof NetworkStackLatencyPacket) && !$this->cachedForms[$player->getId()][$this->currentForms[$pid]]) {
            $times = 10; // 10 * 5 / 20 = 2,5(s)
            $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use($player, &$times) : void {
                if($times-- === 0 || !$player->isOnline()){
                    if ($times === 0) {
                        $this->cachedForms[$player->getId()][] = true;
                    }
                    throw new CancelTaskException();
                }
                self::sendUpdate($player, $this->update_mode);
            }), 5);
        }
    }

    /**
     * @param DataPacketSendEvent $event
     * @priority MONITOR
     */
    public function onDataPacketSend(DataPacketSendEvent $event) : void{
        foreach($event->getPackets() as $packet){
            if($packet instanceof ModalFormRequestPacket){
                foreach($event->getTargets() as $target){
                    $player = $target->getPlayer();
                    if ($player === null || !$player->isConnected() || !$player->isOnline()) {
                        continue;
                    }
                    $this->onPacketSend($player, $packet->formId);
                }
            }
        }
    }

    public function onLeft(PlayerQuitEvent $event) : void{
        unset($this->cachedForms[$event->getPlayer()->getId()], $this->currentForms[$event->getPlayer()->getId()]);
    }
}
