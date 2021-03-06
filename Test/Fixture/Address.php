<?php
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
App::uses('CakeDocument', 'MongoCake.Model');

/** @ODM\EmbeddedDocument */
class Address extends CakeDocument {
    /** @ODM\String */
    private $street;

    /** @ODM\String */
    private $city;

    /** @ODM\String */
    private $state;

    /** @ODM\String */
    private $postalCode;

	public static $useDbConfig = 'testMongo';

    public function getStreet()
    {
        return $this->street;
    }

    public function setStreet($street)
    {
        $this->street = $street;
    }

    public function getCity()
    {
        return $this->city;
    }

    public function setCity($city)
    {
        $this->city = $city;
    }

    public function getState()
    {
        return $this->state;
    }

    public function setState($state)
    {
        $this->state = $state;
    }

    public function getPostalCode()
    {
        return $this->postalCode;
    }

    public function setPostalCode($postalCode)
    {
        $this->postalCode = $postalCode;
    }
}