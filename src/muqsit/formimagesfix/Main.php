<?php

declare(strict_types=1);

namespace muqsit\formimagesfix;

use pocketmine\entity\Attribute;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
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
			                    $ts = mt_rand() * 1000;
			                    $pk = new NetworkStackLatencyPacket();
			                    $pk->timestamp = $ts;
			                    $pk->needResponse = true;
			                    $player->getNetworkSession()->sendDataPacket($pk);
			                    if($player->isOnline()){
			                        $times = 5;
			                        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(static function() use($player, $target, &$times) : void{
			                            $entries = [];
			                            $attr = $player->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL);
			                            /** @noinspection NullPointerExceptionInspection */
			                            $entries[] = new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []);
			                            $target->sendDataPacket(UpdateAttributesPacket::create($player->getId(), $entries, 0));
			                        }), 10);
                    			}
				}
			}
		}
	}
}
