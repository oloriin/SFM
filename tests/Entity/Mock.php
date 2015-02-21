<?php
class Entity_Mock extends \SFM\Entity
{
    /**
     * @return int
     */
    public function getId()
    {
        return (int) $this->id;
    }

    /**
     * @return string
     */
    public function getText()
    {
        return (string) $this->text;
    }
}