<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 12.03.2020
 * Time: 19:30
 */

namespace Exodus4D\Pathfinder\Controller\Api\Rest;


use Exodus4D\Pathfinder\Lib\Config;
use Exodus4D\Pathfinder\Model\Pathfinder;

class Map extends AbstractRestController {

    /**
     * error message missing character right for map delete
     */
    const ERROR_MAP_DELETE = 'Character %s does not have sufficient rights for map delete';

    /**
     * @param \Base $f3
     * @param       $test
     * @throws \Exception
     */
    public function put(\Base $f3, $test){
        $requestData = $this->getRequestData($f3);

        /**
         * @var $map Pathfinder\MapModel
         */
        $map = Pathfinder\AbstractPathfinderModel::getNew('MapModel');
        $mapData = $this->update($map, $requestData)->getData();

        $this->out($mapData);
    }

    /**
     * @param \Base $f3
     * @param       $params
     * @throws \Exception
     */
    public function patch(\Base $f3, $params){
        $requestData = $this->getRequestData($f3);
        $mapData = [];

        if($mapId = (int)$params['id']){
            $activeCharacter = $this->getCharacter();

            /**
             * @var $map Pathfinder\MapModel
             */
            $map = Pathfinder\AbstractPathfinderModel::getNew('MapModel');
            $map->getById($mapId);
            if($map->hasAccess($activeCharacter)){
                $mapData = $this->update($map, $requestData)->getData(true);
            }
        }

        $this->out($mapData);
    }

    /**
     * @param \Base $f3
     * @param       $params
     * @throws \Exception
     */
    public function delete(\Base $f3, $params){
        $deletedMapIds = [];

        if($mapId = (int)$params['id']){
            $activeCharacter = $this->getCharacter();

            /**
             * @var $map Pathfinder\MapModel
             */
            $map = Pathfinder\AbstractPathfinderModel::getNew('MapModel');
            $map->getById($mapId);

            if($map->hasAccess($activeCharacter)){
                // check if character has delete right for map type
                $hasRight = true;
                if($hasRight){
                    $map->setActive(false);
                    $map->save($activeCharacter);
                    $deletedMapIds[] = $mapId;
                    // broadcast map delete
                    $this->broadcastMapDeleted($mapId);
                }else{
                    $f3->set('HALT', true);
                    $f3->error(401, sprintf(self::ERROR_MAP_DELETE, $activeCharacter->name));
                }
            }
        }

        $this->out($deletedMapIds);
    }

    /**
     * @param Pathfinder\MapModel $map
     * @param array               $mapData
     * @return Pathfinder\MapModel
     * @throws \Exception
     */
    private function update(Pathfinder\MapModel $map, array $mapData) : Pathfinder\MapModel {
        $activeCharacter = $this->getCharacter();

        $map->setData($mapData);
        $typeChange = $map->changed('typeId');
        $map->save($activeCharacter);

        $accessChangeCount = 0;
        $mapDefaultConf = Config::getMapsDefaultConfig();

        if($accessChangeCount){
            $map->touch('updated');
            $map->save($activeCharacter);
        }

        // reload the same map model (refresh)
        // this makes sure all data is up2date
        $map->getById($map->_id, 0);

        // broadcast map Access -> and send map Data
        $this->broadcastMapAccess($map);

        return $map;
    }

    /**
     * broadcast characters with map access rights to WebSocket server
     * -> if characters with map access found -> broadcast mapData to them
     * @param Pathfinder\MapModel $map
     * @throws \Exception
     */
    protected function broadcastMapAccess(Pathfinder\MapModel $map){
        $mapAccess =  [
            'id' => $map->_id,
            'name' => $map->name,
            'characterIds' => array_map(function($data){
                return $data->id;
            }, $map->getCharactersData())
        ];

        $this->getF3()->webSocket()->write('mapAccess', $mapAccess);

        // map has (probably) active connections that should receive map Data
        $this->broadcastMap($map, true);
    }

    /**
     * broadcast map delete information to clients
     * @param int $mapId
     */
    private function broadcastMapDeleted(int $mapId){
        $this->getF3()->webSocket()->write('mapDeleted', $mapId);
    }
}
