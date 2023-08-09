<?php

namespace helpers;

require_once __DIR__ . '/../../../../init.php';

use Exception;
use Illuminate\Database\Capsule\Manager as Capsule;

class LexofficeClient
{
    public function __construct($vars, \lexoffice_client $lexoffice_client)
    {
        $this->vars = $vars;
        $this->lexofficeClient = $lexoffice_client;
        $this->userID = $this->getUserIdByVars();

        $this->isEuropean = $this->isEuropean();
        $this->isBusiness = $this->isBusiness();
    }


    public function getUserIdByVars(): int
    {
        $possibleKeys = ['userid', 'user_id', 'clientid', 'client_id'];

        foreach ($possibleKeys as $key) {
            if (array_key_exists($key, $this->vars)) {
                return $this->vars[$key];
            }
        }

        throw new Exception('COULD NOT FETCH USER ID');
    }

    public function isEuropean(): bool
    {
        $country = Capsule::table('tblclients')->where('id', $this->userID)->first()->country;
        return in_array($country, ['DE', 'AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK']);
    }

    public function isBusiness(): bool
    {
        $companyName = Capsule::table('tblclients')->where('id', $this->userID)->first()->companyname;
        return !empty($companyName) && strlen($companyName) != 0;
    }

    public function integrate()
    {
        if ($this->isClientIntegrated()) {
            $this->updateContact();
        } else {
            $this->createContact();
        }
    }

    public function isClientIntegrated(): bool
    {
        return Capsule::table('everhype_lexoffice_contacts')->where('userid', $this->userID)->count() == 1;
    }

    private function updateContact()
    {
        $fields = $this->getFields();

        $this->lexofficeClient->update_contact($this->getLexofficeID(), $fields);

        # everything is ok
        Capsule::table('everhype_lexoffice_contacts')->where('userid', $this->userID)->update([
            'version' => $fields['version']
        ]);
        Capsule::commit();
    }

    private function getFields(): array
    {
        $fields = array(
            'version' => $this->getVersion(),
            'roles' => array(
                'customer' => array(
                    'number' => ''
                )
            ),
            'addresses' => array(
                'billing' => array(
                    array(
                        'street' => $this->vars['address1'],
                        'zip' => $this->vars['postcode'],
                        'city' => $this->vars['city'],
                        'countryCode' => $this->vars['country'],
                    )
                )
            ),
            'emailAddresses' => array(
                'business' => array(
                    $this->vars['email']
                ),
            ),
            'note' => 'Importiert aus WHMCS. NutzerID - ' . $this->userID
        );


        if (!empty($this->vars['companyname']) && strlen($this->vars['companyname']) != 0) {
            # Customer is business owner
            $fields['company'] = array(
                'name' => $this->vars['companyname'],
                'street' => $this->vars['address1'],
                'zip' => $this->vars['postcode'],
                'city' => $this->vars['city'],
                'countryCode' => $this->vars['country'],
                'contactPersons' => array(
                    array(
                        'salutation' => 'Herr/Frau',
                        'firstName' => $this->vars['firstname'],
                        'lastName' => $this->vars['lastname'],
                        'emailAddress' => $this->vars['email'],
                    )
                )
            );
        } else {
            $fields["person"] = array(
                'salutation' => 'Herr/Frau',
                'firstName' => $this->vars['firstname'],
                'lastName' => $this->vars['lastname'],
            );
        }

        return $fields;
    }

    public function getVersion()
    {
        if ($this->isClientIntegrated()) {
            return $this->getVersionFromAPI();
        } else {
            return 0;
        }
    }

    private function getVersionFromAPI()
    {
        $result = $this->lexofficeClient->get_contact($this->getLexofficeID());
        return $result->version;
    }

    public function getLexofficeID()
    {
        return Capsule::table('everhype_lexoffice_contacts')->where('userid', $this->userID)->first()->lexoffice_id;
    }

    private function createContact()
    {
        $fields = $this->getFields();

        $createResponse = $this->lexofficeClient->create_contact($fields);

        # everything is ok
        Capsule::table('everhype_lexoffice_contacts')->insert([
            'userid' => $this->userID,
            'lexoffice_id' => $createResponse->id,
            'version' => $createResponse->version
        ]);
        Capsule::commit();
    }

    public function getVarByKey($key)
    {
        if (array_key_exists($key, $this->vars)) {
            return $this->vars[$key];
        }
        return null;
    }
}