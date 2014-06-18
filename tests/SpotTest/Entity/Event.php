<?php
namespace SpotTest;

/**
 * Post
 *
 * @package Spot
 */
class Event extends \Spot\Entity
{
    protected static $table = 'test_events';

    public static function fields()
    {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'title' => ['type' => 'string', 'required' => true],
            'description' => ['type' => 'text', 'required' => true],
            'type' => ['type' => 'string', 'required' => true, 'options' => [
                'free' => 'Free',
                'private' => 'Private (Ticket Required)',
                'vip' => 'VIPs only'
            ]],
            'token' => ['type' => 'string', 'required' => true],
            'date_start' => ['type' => 'datetime', 'required' => true, 'validation' => [
                'dateAfter' => new \DateTime()
            ]],
            'date_created' => ['type' => 'datetime']
        ];
    }

    public static function hooks()
    {
        return [
            'beforeInsert' => ['hookGenerateToken'],
            'afterSave' => ['hookUpdateSearchIndex']
        ];
    }

    public function hookGenerateToken(\Spot\Mapper $mapper) {
        $this->token = uniqid();
    }

    public function hookUpdateSearchIndex(\Spot\Mapper $mapper) {
        $result = $mapper->upsert('SpotTest\Entity\Event\Search', [
            'event_id' => $this->id,
            'body'     => $this->title . ' ' . $this->description
        ], [
            'event_id' => $this->id
        ]);
    }
}
