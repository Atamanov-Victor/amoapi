<?php
/*
 * Good day! This version of AmoHelper runs on saving tokens to json file
 * But you can easily change it to database
 * Choose this method if you are setting this up on some easy landing projects/etc
 * By the way, dont forget to set .htaccess file only on post request access to your token json files!
 *
 */

use AmoCRM\AmoCRM\Models\CustomFieldsValues\TrackingDataCustomFieldValuesModel;
use AmoCRM\AmoCRM\Models\CustomFieldsValues\ValueCollections\TrackingDataCustomFieldValueCollection;
use AmoCRM\AmoCRM\Models\CustomFieldsValues\ValueModels\TrackingDataCustomFieldValueModel;
use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\BaseApiCollection;
use AmoCRM\Collections\CatalogElementsCollection;
use AmoCRM\Collections\ContactsCollection;
use AmoCRM\Collections\CustomFields\CustomFieldEnumsCollection;
use AmoCRM\Collections\CustomFields\CustomFieldsCollection;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Collections\Leads\Unsorted\FormsUnsortedCollection;
use AmoCRM\Collections\LinksCollection;
use AmoCRM\Collections\NotesCollection;
use AmoCRM\Exceptions\AmoCRMApiException;
use AmoCRM\Exceptions\AmoCRMApiNoContentException;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Helpers\EntityTypesInterface;
use AmoCRM\Models\CatalogElementModel;
use AmoCRM\Models\ContactModel;
use AmoCRM\Models\CustomFields\DateTimeCustomFieldModel;
use AmoCRM\Models\CustomFields\EnumModel;
use AmoCRM\Models\CustomFields\SelectCustomFieldModel;
use AmoCRM\Models\CustomFields\TextCustomFieldModel;
use AmoCRM\Models\CustomFieldsValues\BaseCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\CheckboxCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\DateCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\DateTimeCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\SelectCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\MultitextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\BaseCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\CheckboxCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\DateCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\DateTimeCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\MultitextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\SelectCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\DateTimeCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\MultitextCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\SelectCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use AmoCRM\Models\NoteType\CommonNote;
use AmoCRM\Models\Unsorted\FormsMetadata;
use AmoCRM\Models\Unsorted\FormUnsortedModel;
use AmoCRM\OAuth2\Client\Provider\AmoCRMException;
use Carbon\Carbon;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use TypeError;


class AmoHelper
{
    /**
     * @var AmoCRMApiClient
     */
    protected $apiClient;
    protected $unsortedService;

    /**
     * AmoHelper constructor.
     * @param $clientId
     * @param $clientSecret
     * @param $clientRedirectUri
     * @throws AmoCRMoAuthApiException
     */
    public function __construct($clientId, $clientSecret, $clientRedirectUri)
    {
        $json_auth = json_decode(file_get_contents('token_auth.json'));
        $settings = json_decode(file_get_contents('settings.json'));
        $authCredit = $json_auth;
        $this->apiClient = new AmoCRMApiClient($clientId, $clientSecret, $clientRedirectUri);
        $this->apiClient->setAccountBaseDomain($settings->base_domain);
        if ($authCredit->client_auth_code !== $settings->client_auth_code) {
            $code = $settings->client_auth_code;
            $options = $this->getTokensArrayByCode($code);
            $this->saveTokens($options);
        } else {
            $options = $this->getTokensArrayToSettings();
        }
        $token = new AccessToken($options);
        if ($token->hasExpired()) {
            $this->refreshTokens();
        } else {
            $this->setTokens($options);
        }
    }

    /**
     * @param array $options
     * @return bool
     */
    public function setTokens(array $options = [])
    {
        $accessToken = new AccessToken($options);
        $this->apiClient
            ->setAccessToken($accessToken)
            ->onAccessTokenRefresh(
                function () use ($accessToken) {
                    $this->refreshTokens();
                });
        return true;
    }

    /**
     * @param $code
     * @return AccessTokenInterface
     * @throws AmoCRMoAuthApiException
     */
    public function getTokensByCode($code)
    {
        return $this->apiClient->getOAuthClient()->getAccessTokenByCode($code);
    }

    /**
     * @param array $options
     * @return bool
     */
    public function saveTokens(array $options = [])
    {
        $authCredit = json_decode(file_get_contents('token_auth.json'));
        $settings = json_decode(file_get_contents('settings.json'));
            $authCredit->client_auth_code = $settings->client_auth_code;
            $authCredit->access_token = $options['access_token'];
            $authCredit->refresh_token = $options['refresh_token'];
            $authCredit->expires = $options['expires'];
            $authCredit->updated_at = date('Y-m-d G:i:s');
            $authCredit_json = json_encode($authCredit);
            file_put_contents('token_auth.json', $authCredit_json);
        return true;
    }

    /**
     * @param array $options
     * @return bool
     * @throws AmoCRMoAuthApiException
     */
    public function refreshTokens()
    {
        $options = $this->getTokensArrayToSettings();
        $oldTokens = new AccessToken($options);
        $tokens = $this->apiClient->getOAuthClient()->getAccessTokenByRefreshToken($oldTokens);
        $options = [
            'access_token' => $tokens->getToken(),
            'refresh_token' => $tokens->getRefreshToken(),
            'expires' => $tokens->getExpires()
        ];
        $this->saveTokens($options);
        $this->setTokens($options);
        return true;
    }

    /**
     * @param $code
     * @return array
     * @throws AmoCRMoAuthApiException
     */
    public function getTokensArrayByCode($code)
    {
        $tokens = $this->getTokensByCode($code);
        return [
            'access_token' => $tokens->getToken(),
            'refresh_token' => $tokens->getRefreshToken(),
            'expires' => $tokens->getExpires()
        ];
    }

    /**
     * @return array
     */
    public function getTokensArrayToSettings()
    {
        $authCredit = json_decode(file_get_contents('token_auth.json'));
        return [
            'access_token' => $authCredit->access_token,
            'refresh_token' => $authCredit->refresh_token,
            'expires' => $authCredit->expires
        ];
    }


    public function isRefreshTokenExpiring() : Bool
    {
        $currentDate = new Carbon();
        $authCredit = json_decode(file_get_contents('token_auth.json'));
        $updated = new Carbon($authCredit->updated_at);
        $fmonth = $updated->addMonths(2);
        $updated = new Carbon($authCredit->updated_at);
        $smonth = $updated->addMonths(3);
        if ($currentDate->between($fmonth, $smonth)) {
            return true;
        } else {
            return false;
        }
    }
    function printError(AmoCRMApiException $e)
    {
        $errorTitle = $e->getTitle();
        $code = $e->getCode();
        $debugInfo = var_export($e->getLastRequestInfo(), true);

        $error = <<<EOF
        Error: $errorTitle
        Code: $code
        Debug: $debugInfo
EOF;

        echo '<pre>' . $error . '</pre>';
    }
    public function setLead(array $leadParams = []): LeadModel
    {
        return (new LeadModel())
            ->setName($leadParams['leadName']);
//            ->setPrice($leadParams['leadPrice']);
    }

    public function setContact(array $contactParams = []): ContactModel
    {
        return new ContactModel();
    }

    // example given, for setting custom fields for unsorted lead
    // You can add your new custom field variables, by setting your unique field ID or Code ID
    // as in given example
    public function setCustomFields(array $customFieldsParams = []): CustomFieldsValuesCollection
    {
        $customFieldsValuesCollection = new CustomFieldsValuesCollection();
        if ($customFieldsParams['phone']) {
            $customFieldsValuesCollection
                ->add((new MultitextCustomFieldValuesModel())->setFieldCode('PHONE')
                    ->setValues((new MultitextCustomFieldValueCollection())
                        ->add((new MultitextCustomFieldValueModel())
                            ->setEnum('WORK')
                            ->setValue($customFieldsParams['phone']))));
        }
        return $customFieldsValuesCollection;
    }
    // Just a simple parse function from given url to UTM varibles, for future adding in utm stats
    // or in your custom fields
    public function getUtm($httpref) : array {
        $pos= stripos($httpref, '?');
        if($pos) {
            $link = mb_substr($httpref, $pos + 1);
            $link = explode('&', $link);
            $utm = [];
            $utm['USOURCE'] = mb_substr($link[0], stripos($link[0], '=') + 1);
            $utm['UCAMPAIGN'] = mb_substr($link[1], stripos($link[1], '=') + 1);
            $utm['UTERM'] = mb_substr($link[2], stripos($link[2], '=') + 1);
            $utm['UMEDIUM'] = mb_substr($link[3], stripos($link[3], '=') + 1);
            return $utm;
        } else {
            return [];
        }
    }
    // Setting lead function
    public function setUnsortedForm(array $unsortedFormParams = []): BaseApiCollection
    {
        $formsUnsortedCollection = new FormsUnsortedCollection();
        $formUnsorted = new FormUnsortedModel();
        $formsMetadata = new FormsMetadata();
        $UnsortedLead = $this->setLead($unsortedFormParams['leadParams']);

        $contactCustomFields = $this->setCustomFields($unsortedFormParams['customFieldsParams']);
        $unsortedContact = $this->setContact();
        $unsortedContact->setCustomFieldsValues($contactCustomFields);
        $unsortedContactsCollection = (new ContactsCollection())->add($unsortedContact);
        $formsMetadata->setFormId(($unsortedFormParams['formName']))
            ->setFormName($unsortedFormParams['formName'])
            ->setFormPage($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])
            ->setFormSentAt(time())
            ->setReferer('https://google.com/search')
            ->setIp($_SERVER['REMOTE_ADDR']);
        $formUnsorted->setSourceName($unsortedFormParams['sourceName'])
            ->setSourceUid(($unsortedFormParams['sourceName']))
            ->setCreatedAt(time())
            ->setMetadata($formsMetadata)
            ->setLead($UnsortedLead)
            ->setContacts($unsortedContactsCollection)
            ->setPipelineId(3913081);
        $formsUnsortedCollection->add($formUnsorted);
        $unsortedService = $this->apiClient->unsorted();
        try {
            $returnedFormsUnsortedCollection = $unsortedService->add($formsUnsortedCollection);
        } catch (AmoCRMApiException $e) {
            throw new AmoCRMApiException($e);
        }
        return $returnedFormsUnsortedCollection;
    }
    // if you nned sime notes, you can use this function
    public function setNotes(array $notesParams = [])
    {
        $notesCollection = new NotesCollection();
        $commonNote = new CommonNote();
        try {
            $entityId = $this->setUnsortedForm($notesParams['unsortedFormParams']);
        } catch (AmoCRMApiException $e) {
            throw new AmoCRMApiException($e);
        }
        $commonNote->setEntityId($entityId->first()->lead->id)
            ->setText($notesParams['textNote']);
        $notesCollection->add($commonNote);
        try {
            $leadNotesService = $this->apiClient->notes(EntityTypesInterface::LEADS);
            $notesCollection = $leadNotesService->add($notesCollection);
        } catch (AmoCRMApiException $e) {
            throw new AmoCRMApiException($e);
        }
        return true;
    }

    public function getCustomFields() {
        $customFieldsService = $this->apiClient->customFields(EntityTypesInterface::LEADS);
        $fieldToDelete = $customFieldsService->get();
        return $fieldToDelete;
    }

    public function removeCustomFields() {
        $customFieldsService = $this->apiClient->customFields(EntityTypesInterface::LEADS);
        $fieldToDelete = $customFieldsService->get();
        $fieldToDelete = $fieldToDelete->getBy('code', 'UTM');
        $customFieldsService->deleteOne($fieldToDelete);
        $fieldToDelete = $customFieldsService->get();
        $fieldToDelete = $fieldToDelete->getBy('code', 'UTMSS');
        $customFieldsService->deleteOne($fieldToDelete);
    }

    public function editCustomFileds($newvalue, $oldvalue) {
        $customFieldsService = $this->apiClient->customFields(EntityTypesInterface::LEADS);
        $car_field = $customFieldsService->get()->getBy('code', 'CAR');
        $car_enums = $car_field->getEnums();
        //массив c значениями и ид в амо
        $updateCar = $car_enums->getBy('value', $oldvalue);
        $updateCar->setValue($newvalue);
        $car_field->setEnums($car_enums);
        $customFieldsService->updateOne($car_field);
        return $car_field;
    }

    // This function setting custom fields for your lead. It will be in every lead in your account as setted
    // even in existed
    public function setAccountCustomFileds() {
        $customFieldsService = $this->apiClient->customFields(EntityTypesInterface::LEADS);
        $customFieldsCollection = new CustomFieldsCollection();
        // below given example of setting custom fields for UTM marks (if you dint wont to use existed once)
//        $af = new TextCustomFieldModel();
//        $af ->setCode('USOURCE')
//            ->setSort(10)
//            ->setName('utm source');
//        $customFieldsCollection->add($af);
//        $bf = new TextCustomFieldModel();
//        $bf ->setCode('UCAMPAIGN')
//            ->setSort(10)
//            ->setName('utm campaign');
//        $customFieldsCollection->add($bf);
//        $cf = new TextCustomFieldModel();
//        $cf ->setCode('UTERM')
//            ->setSort(10)
//            ->setName('utm term');
//        $customFieldsCollection->add($cf);
//        $df = new TextCustomFieldModel();
//        $df ->setCode('DEPOSIT')
//            ->setSort(10)
//            ->setName('Залог');
//        $customFieldsCollection->add($df);
//        $cf = new SelectCustomFieldModel();
//        $cf
//            ->setName('Машина')
//            ->setCode('CAR')
//            ->setEnums(
//                (new CustomFieldEnumsCollection())
//                    ->add(
//                        (new EnumModel())
//                            ->setValue('TEST')
//                            ->setSort(10)
//                    )
//                    ->add(
//                        (new EnumModel())
//                            ->setValue('TEST2')
//                            ->setSort(20)
//                    )
//            );
//        $customFieldsCollection->add($cf);
        try {
            $customFieldsCollection = $customFieldsService->add($customFieldsCollection);
        } catch(AmoCRMApiException $e) {
            var_dump($customFieldsService->getLastRequestInfo());
        }
    }
    // This example was taken from existed project.
    // I was adding a car to select field in lead params, as an option (in was a select field).
    public function addCar($name) {
        $customFieldsService = $this->apiClient->customFields(EntityTypesInterface::LEADS);
        $customFields = $this->apiClient->customFields(EntityTypesInterface::LEADS)->get();
        $carField = $customFields->getBy('code', 'CAR');
        $enums = $carField->getEnums();
        $enums->add(
            (new EnumModel())
            ->setValue($name)
            ->setSort(10)
        );
        $carField->setEnums($enums);
        $carField = $customFieldsService->updateOne($carField);

        //Добавление машины в товары
        $catalogsCollection = $this->apiClient->catalogs()->get();
        $catalog = $catalogsCollection->getBy('name', 'Товары');
        $catalogElementsCollection = new CatalogElementsCollection();
        $catalogElement = new CatalogElementModel();
        $catalogElement->setName($name);
        $catalogElementsCollection->add($catalogElement);
        $catalogElementsService = $this->apiClient->catalogElements($catalog->getId());
        try {
            $catalogElementsService->add($catalogElementsCollection);
        } catch (AmoCRMApiException $e) {
            printError($e);
            die;
        }
    }

    // Setting custom fields variables for unsorted lead.
    public function setLeadCustomFields($unsortedLeadParams) :  CustomFieldsValuesCollection {
        $customFieldsValuesCollection = new CustomFieldsValuesCollection();
        // Example for pre-created custom fields with code :
//        if (isset($unsortedLeadParams['USOURCE'])) {
//            $customFieldsValuesCollection
//                ->add((new TextCustomFieldValuesModel())->setFieldCode('USOURCE')
//                    ->setValues((new TextCustomFieldValueCollection())
//                        ->add((new TextCustomFieldValueModel())
//                            ->setValue($unsortedLeadParams['USOURCE']))));
//        }
//        if (isset($unsortedLeadParams['UCAMPAIGN'])) {
//            $customFieldsValuesCollection
//                ->add((new TextCustomFieldValuesModel())->setFieldCode('UCAMPAIGN')
//                    ->setValues((new TextCustomFieldValueCollection())
//                        ->add((new TextCustomFieldValueModel())
//                            ->setValue($unsortedLeadParams['UCAMPAIGN']))));
//        }
//        if (isset($unsortedLeadParams['UTERM'])) {
//            $customFieldsValuesCollection
//                ->add((new TextCustomFieldValuesModel())->setFieldCode('UTERM')
//                    ->setValues((new TextCustomFieldValueCollection())
//                        ->add((new TextCustomFieldValueModel())
//                            ->setValue($unsortedLeadParams['UTERM']))));
//        }
//        if (isset($unsortedLeadParams['UMEDIUM'])) {
//            $customFieldsValuesCollection
//                ->add((new TextCustomFieldValuesModel())->setFieldCode('UMEDIUM')
//                    ->setValues((new TextCustomFieldValueCollection())
//                        ->add((new TextCustomFieldValueModel())
//                            ->setValue($unsortedLeadParams['UMEDIUM']))));
//        }
        if (isset($unsortedLeadParams['deposit'])) {
            $customFieldsValuesCollection
                ->add((new TextCustomFieldValuesModel())->setFieldCode('DEPOSIT')
                    ->setValues((new TextCustomFieldValueCollection())
                        ->add((new TextCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['deposit']))));
        }
        if (isset($unsortedLeadParams['price'])) {
            $customFieldsValuesCollection
                ->add((new TextCustomFieldValuesModel())->setFieldID(212449)
                    ->setValues((new TextCustomFieldValueCollection())
                        ->add((new TextCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['price']))));
        }
        if($unsortedLeadParams['car']) {
            $customFieldsValuesCollection
                ->add((new SelectCustomFieldValuesModel())->setFieldId(62125)

                    ->setValues((new SelectCustomFieldValueCollection())
                        ->add((new SelectCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['car']))));
        }
        // example for existed in AMOCRM fields.
        // Be careful with Tracking data model!
        if(isset($unsortedLeadParams['USOURCE'])) {
            $customFieldsValuesCollection
                ->add((new TrackingDataCustomFieldValuesModel())->setFieldCode('UTM_SOURCE')
                    ->setValues((new TrackingDataCustomFieldValueCollection())
                        ->add((new TrackingDataCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['USOURCE']))));
        }
        if(isset($unsortedLeadParams['UMEDIUM'])) {
            $customFieldsValuesCollection
                ->add((new TrackingDataCustomFieldValuesModel())->setFieldCode('UTM_MEDIUM')
                    ->setValues((new TrackingDataCustomFieldValueCollection())
                        ->add((new TrackingDataCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['UMEDIUM']))));
        }
        if(isset($unsortedLeadParams['UTERM'])) {
            $customFieldsValuesCollection
                ->add((new TrackingDataCustomFieldValuesModel())->setFieldCode('UTM_TERM')
                    ->setValues((new TrackingDataCustomFieldValueCollection())
                        ->add((new TrackingDataCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['UTERM']))));
        }
        if(isset($unsortedLeadParams['UCAMPAIGN'])) {
            $customFieldsValuesCollection
                ->add((new TrackingDataCustomFieldValuesModel())->setFieldCode('UTM_CAMPAIGN')
                    ->setValues((new TrackingDataCustomFieldValueCollection())
                        ->add((new TrackingDataCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['UCAMPAIGN']))));
        }
        if(isset($unsortedLeadParams['yclid'])) {
            $customFieldsValuesCollection
                ->add((new TrackingDataCustomFieldValuesModel())->setFieldCode('YCLID')
                    ->setValues((new TrackingDataCustomFieldValueCollection())
                        ->add((new TrackingDataCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['gclid']))));
        }
        if(isset($unsortedLeadParams['gclid'])) {
            $customFieldsValuesCollection
                ->add((new TrackingDataCustomFieldValuesModel())->setFieldCode('GCLID')
                    ->setValues((new TrackingDataCustomFieldValueCollection())
                        ->add((new TrackingDataCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['gclid']))));
        }
        if($unsortedLeadParams['start_date']) {
            $customFieldsValuesCollection
                ->add((new DateTimeCustomFieldValuesModel())->setFieldId(000000)
                    ->setValues((new DateTimeCustomFieldValueCollection())
                        ->add((new DateTimeCustomFieldValueModel())
                            ->setValue($unsortedLeadParams['start_date'])
                        )));
        }
        if($unsortedLeadParams['end_date']) {
            $customFieldsValuesCollection
                ->add((new DateTimeCustomFieldValuesModel())->setFieldId(000000)
                    ->setValues((new DateTimeCustomFieldValueCollection())
                        ->add((new DateTimeCustomFieldValueModel())
                        ->setValue($unsortedLeadParams['end_date'])
                        )));
        }

        return $customFieldsValuesCollection;
    }

    // Example from again existed project. In this case for easier understanding specific variables
    // was given as function params. BUT! You can easily send in as array in your own function.
    public function booking($start_date, $end_date, $car, $tel, $deposit, $price, $url, $utmdata=[]) {

        $formsUnsortedCollection = new FormsUnsortedCollection();
        $formUnsorted = new FormUnsortedModel();
        $formsMetadata = new FormsMetadata();
        $UnsortedLead = $this->setLead( [
            'leadName' => 'Заявка с сайта ' . $car,
        ]);
        if(isset($url)) {
            $unsortedLeadParams = $this->getUtm($url);
        }
        if(isset($utmdata['yclid'])) {
            $unsortedLeadParams['yclid'] = $utmdata['yclid'];
        }
        if(isset($utmdata['gclid'])) {
            $unsortedLeadParams['gclid'] = $utmdata['gclid'];
        }
        $unsortedLeadParams['car'] = $car;
        $unsortedLeadParams['start_date'] = $start_date;
        $unsortedLeadParams['end_date'] = $end_date;
        $unsortedLeadParams['deposit'] = $deposit;
        $unsortedLeadParams['price'] = $price;
        $leadCustomFields = $this->setLeadCustomFields($unsortedLeadParams);
        $UnsortedLead->setCustomFieldsValues($leadCustomFields);
        $UnsortedLead->setPrice(intval($price));
        $contactCustomFields = $this->setCustomFields([
            'phone' => $tel
        ]);
        $unsortedContact = $this->setContact();
        $unsortedContact->setCustomFieldsValues($contactCustomFields);

        $unsortedContactsCollection = (new ContactsCollection())->add($unsortedContact);
        $formsMetadata->setFormId('my_form')
            ->setFormName('form_name')
            ->setFormPage('Заявка с сайта ' . $car)
            ->setFormSentAt(time())
            ->setReferer('https://google.com/search')
            ->setIp($_SERVER['REMOTE_ADDR']);
        $formUnsorted->setSourceName('Заявка с сайта ' . $car)
            ->setSourceUid('letai')
            ->setCreatedAt(time())
            ->setMetadata($formsMetadata)
            ->setLead($UnsortedLead)
            ->setContacts($unsortedContactsCollection);
        $formsUnsortedCollection->add($formUnsorted);
        $unsortedService = $this->apiClient->unsorted();
        try {
            $addedLead = $unsortedService->add($formsUnsortedCollection);
        } catch (AmoCRMApiException $e) {
            var_dump($unsortedService->getLastRequestInfo());
        }
        // Linking selected car to lead.
        $catalogElementsCollection = new CatalogElementsCollection();
        $catalogsCollection = $this->apiClient->catalogs()->get();
        $catalog = $catalogsCollection->getBy('name', 'Товары');
        $catalogElementsService = $this->apiClient->catalogElements($catalog->getId());
        $catalogElementsCollection = $catalogElementsService->get();
        $carElement = $catalogElementsCollection->getBy('name', $car);
        $carElement->setQuantity(1);
        $linkToLead = $this->apiClient->leads()->getOne($addedLead->first()->lead->id);
        $links = new LinksCollection();
        $links->add($carElement);
        try {
            $this->apiClient->leads()->link($linkToLead, $links);
        } catch (AmoCRMApiException $e) {
            printError($e);
            die;
        }

    }


    // function for getting existed leads. Could be useful for parsing some data to frontend, or testing
    // or any other things that you wish to do!
    public function getLeads() {
        $lead = [];
        try {
            $lead = $this->apiClient->leads()->get();
            $lead->toArray();
            return $lead->toArray();
        } catch (AmoCRMApiNoContentException $e) {
            return $lead;
        }
    }
}
