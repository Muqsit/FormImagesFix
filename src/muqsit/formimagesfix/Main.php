<?php
declare(strict_types=1);

namespace muqsit\formimagesfix;

use pocketmine\entity\Attribute;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
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
    public const HARDCODED_TIMESTAMP_MODIFIER = 1000000; //NetworkStackLatencyPacket response returns a timestamp that many times the requested value...

    public const UPDATE_TITLE = 0;
    public const UPDATE_MESSAGE = 1;
    public const UPDATE_ATTRIBUTE = 2;

    protected int $update_mode = self::UPDATE_ATTRIBUTE;

    private array $cache = [];

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
        $pk = NetworkStackLatencyPacket::request($ts = time()); // time() -> 0?
        $player->getNetworkSession()->sendDataPacket($pk);
        $this->cache[$player->getId()][$ts] = 0;
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
        if ($packet instanceof NetworkStackLatencyPacket && isset($this->cache[$pid][$packet->timestamp / self::HARDCODED_TIMESTAMP_MODIFIER])) {
            $times = 7; // 7 * 5 / 20 = 1,75(s) NOTE: this is theoretical
            $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use($player, &$times) : void {
                if($times-- === 0 || !$player->isOnline()){
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
        unset($this->cache[$event->getPlayer()->getId()]);
    }
}
