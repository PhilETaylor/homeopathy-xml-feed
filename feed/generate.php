<?php
header("Content-type: text/xml");

set_time_limit(99999999);
ini_set('memory_limit', '128M');

define('JOOMLA_MINIMUM_PHP', '5.3.10');

if (version_compare(PHP_VERSION, JOOMLA_MINIMUM_PHP, '<')) {
    die('Your host needs to use PHP ' . JOOMLA_MINIMUM_PHP . ' or higher to run this version of Joomla!');
}

define('_JEXEC', 1);
define('JPATH_BASE', __DIR__ . '/../');

require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';

final class xmlFeed
{
    private $_doc;
    private $_root;
    private $_location;
    private $_itemCache;
    private $idsSeen = [];

    public function getOutput()
    {
        $this->_doc = new DomDocument('1.0', 'utf-8');

        // create root node
        $this->_root = $this->_doc->createElement('members');
        $this->_root = $this->_doc->appendChild($this->_root);

        foreach ($this->getData() as $item) {

            // internal cache to prevent duplicates
            $this->_itemCache = $item;

            // reset location
            $this->_location = '';

            /**
             * Create the member node
             */
            $this->_doc->getElementsByTagName('id');
            $parent = NULL;

            if (array_key_exists($item->id, $this->idsSeen)) {
                $member = $this->idsSeen[$item->id];
                unset($item->id);
                unset($item->title);
                unset($item->name);
                unset($item->qualified);
                unset($item->suffix);
                unset($item->optout);
            } else {
                $member = $this->_doc->createElement('member');
                $member = $this->_root->appendChild($member);

            }


            // Populate the member node
            foreach ($this->_itemCache as $k => $v) {
                $this->addMemberData($member, $k, $v);
            }

            // cleanup
            $member->removeChild($member->getElementsByTagName('region')->item(0));
            $member->removeChild($member->getElementsByTagName('town')->item(0));

            $this->idsSeen[$item->id] = $member;
        }

        $this->_doc->preserveWhiteSpace = FALSE;
        $this->_doc->formatOutput = TRUE;
        $xml_string = $this->_doc->saveXML();

        return $xml_string;
    }

    private function getData()
    {
        $db = JFactory::getDbo();
        $sql = 'SELECT
                    (SELECT value from #__djcf_fields_values WHERE field_id = 3 AND item_id = #__djcf_items.id) AS id,
                    1 - published as optout,
                    \'\' as title,
                    name,
                    \'\' as suffix,
                    (SELECT value from #__djcf_fields_values WHERE field_id = 5 AND item_id = #__djcf_items.id) AS town,
                    (SELECT value from #__djcf_fields_values WHERE field_id = 6 AND item_id = #__djcf_items.id) AS region,
                    (SELECT value from #__djcf_fields_values WHERE field_id = 4 AND item_id = #__djcf_items.id) AS qualified,
                    intro_desc as full_address,
                    \'United Kingdom\' AS country,
                    post_code AS postcode,
                    website,
                    email,
                    contact AS phone
                    FROM #__djcf_items
                    ORDER BY id ASC
                     ';

        $db->setQuery($sql);

        return $db->loadObjectList();

    }

    /**
     * @param $member
     * @param $field
     * @param $value
     */
    public function addMemberData($member, $field, $value)
    {
        $addHere = $member;

        switch ($field) {
            //Convert full_address to a location tag
            case 'full_address':
                $this->splitAndAddLocationToMember($member, $value);

                return;
            case 'optout':
                $value = ($value == 1 ? 'true' : 'false');
                break;

            case 'phone':
            case 'email':
            case 'country':
                $value = trim(strip_tags(str_replace(['Tel:'], '', $value)));
                $addHere = $this->_location;
                break;
            case 'website':
                $value = 'http://' . trim(strip_tags(str_replace([' ', 'http://', 'https://'], '', $value)));
                if ($value == 'http://') $value = '';
                $addHere = $this->_location;
                break;
            default:
                break;
        }

        $child = $this->_doc->createElement($field);
        $child = $addHere->appendChild($child);
        $value = $this->_doc->createTextNode(trim($value));
        $child->appendChild($value);

    }

    /**
     * Add the location node
     *
     * @param $member
     * @param $full_address
     */
    public function splitAndAddLocationToMember($member, $full_address)
    {

        $this->_location = $this->_doc->createElement('location');
        $member->appendChild($this->_location);


        $full_address = explode(', ', trim($full_address));

        foreach ($full_address as $k => $line) {
            if (strstr($line, '@')) {
                unset($full_address[$k]);
            }
        }

        // trim the address to 4 lines
        while (count($full_address) > 4) {
            array_pop($full_address);
        }

        $count = 0;

        // Make sure we have 4 address lines
        while (count($full_address) <= 3) {
            $full_address[] = '';
        }

        foreach ($full_address as $addressLine) {


            if ($addressLine == $this->_itemCache->region ||
                $addressLine == $this->_itemCache->town
            ) {
                $this->addMemberData($this->_location, 'address' . ($count + 1), '');
            } else {
                $this->addMemberData($this->_location, 'address' . ($count + 1), $addressLine);
            }
            $count++;
        }


        $this->addMemberData($this->_location, 'city', $this->_itemCache->town);

        if ($this->_itemCache->town != $this->_itemCache->region) {
            $this->addMemberData($this->_location, 'county', $this->_itemCache->region);
        } else {
            $this->addMemberData($this->_location, 'county', '');
        }

        $this->addMemberData($this->_location, 'postcode', $this->_itemCache->postcode);


        unset($this->_itemCache->region);
        unset($this->_itemCache->town);
        unset($this->_itemCache->postcode);
    }
}

$feed = new xmlFeed();

// output to a real xml file so that it can be cached
file_put_contents('feed.xml', $feed->getOutput());

if (APPLICATION_ENV == 'development') {
    echo $feed->getOutput();
} else {
    header('Location: http://www.homeopathy-soh.org/feed/feed.xml?' . time());

}
