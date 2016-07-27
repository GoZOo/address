<?php

namespace Drupal\Tests\address\FunctionalJavascript;

use CommerceGuys\Addressing\Repository\AddressFormatRepositoryInterface;
use CommerceGuys\Addressing\Repository\CountryRepositoryInterface;
use CommerceGuys\Addressing\Repository\SubdivisionRepositoryInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\JavascriptTestBase;
use Drupal\node\Entity\NodeType;

/**
 * Tests the default address widget.
 *
 * @group address
 */
class AddressDefaultWidgetTest extends JavascriptTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'system',
    'language',
    'user',
    'field',
    'field_ui',
    'node',
    'address',
  ];

  /**
   * User with permission to administer entites.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * Address field instance.
   *
   * @var \Drupal\field\FieldConfigInterface
   */
  protected $field;

  /**
   * Entity form display.
   *
   * @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface
   */
  protected $formDisplay;

  /*
   * URL to add new content.
   *
   * @var string
   */
  protected $nodeAddUrl;

  /*
   * URL to field's configuration form.
   *
   * @var string
   */
  protected $fieldConfigUrl;

  /**
   * The country repository.
   *
   * @var CountryRepositoryInterface
   */
  protected $countryRepository;

  /**
   * The subdivision repository.
   *
   * @var SubdivisionRepositoryInterface
   */
  protected $subdivisionRepository;

  /**
   * The address format repository.
   *
   * @var AddressFormatRepositoryInterface
   */
  protected $addressFormatRepository;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Create node bundle for tests.
    $type = NodeType::create(['name' => 'Article', 'type' => 'article']);
    $type->save();

    // Create user that will be used for tests.
    $this->adminUser = $this->drupalCreateUser([
      'create article content',
      'edit own article content',
      'administer content types',
      'administer node fields',
    ]);
    $this->drupalLogin($this->adminUser);

    // Add the address field to the article content type.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_address',
      'entity_type' => 'node',
      'type' => 'address',
    ]);
    $field_storage->save();

    $this->field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'article',
      'label' => 'Address',
    ]);
    $this->field->save();

    // Set article's form display.
    $this->formDisplay = EntityFormDisplay::load('node.article.default');

    if (!$this->formDisplay) {
      EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'article',
        'mode' => 'default',
        'status' => TRUE,
      ])->save();
      $this->formDisplay = EntityFormDisplay::load('node.article.default');
    }
    $this->formDisplay->setComponent($this->field->getName(), [
      'type' => 'address_default',
      'settings' => [
        'default_country' => 'US',
      ],
    ])->save();

    $this->nodeAddUrl = 'node/add/article';
    $this->fieldConfigUrl = 'admin/structure/types/manage/article/fields/node.article.' . $this->field->getName();

    $this->countryRepository = \Drupal::service('address.country_repository');
    $this->subdivisionRepository = \Drupal::service('address.subdivision_repository');
    $this->addressFormatRepository = \Drupal::service('address.address_format_repository');
  }

  /**
   * Tests the country field.
   *
   * Checked:
   * - required/optional status.
   * - default_country widget setting.
   * - available_countries instance setting.
   */
  function testCountries() {
    $session = $this->getSession();

    $field_name = $this->field->getName();
    // Optional field: Country should be optional and set to default_country.
    $this->drupalGet($this->nodeAddUrl);
    $this->assertEmpty((bool) $this->xpath('//select[@name="' . $field_name . '[0][country_code]" and boolean(@required)]'), 'Country is shown as optional.');
    $this->assertOptionSelected($field_name . '[0][country_code]', 'US', 'The configured default_country is selected.');

    // Required field: Country should be required and set to default_country.
    $this->field->setRequired(TRUE);
    $this->field->save();
    $this->drupalGet($this->nodeAddUrl);
    $this->assertNotEmpty((bool) $this->xpath('//select[@name="' . $field_name . '[0][country_code]" and boolean(@required)]'), 'Country is shown as required.');
    $this->assertOptionSelected($field_name . '[0][country_code]', 'US', 'The configured default_country is selected.');

    // All countries should be present in the form.
    $countries = array_keys($this->countryRepository->getList());
    $this->assertOptions($field_name . '[0][country_code]', $countries, 'All countries are present.');

    // Limit the list of available countries.
    $countries = ['US', 'FR', 'BR', 'JP'];
    $edit = [];
    $edit['settings[available_countries][]'] = array_map(function ($country) {
      return $country;
    }, $countries);
    $this->drupalGet($this->fieldConfigUrl);
    $this->submitForm($edit, t('Save settings'));
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($this->nodeAddUrl);
    $this->assertOptions($field_name . '[0][country_code]', $countries, 'The restricted list of available countries is present.');

    // Create an article with one of them.
    $country_code = 'US';
    $session->getPage()->fillField($field_name . '[0][country_code]', 'US');
    $this->waitForAjaxToFinish();

    $address = [
      'recipient' => 'Some Recipient',
      'organization' => 'Some Organization',
      'address_line1' => '1098 Alta Ave',
      'locality' => 'Mountain View',
      'administrative_area' => 'US-CA',
      'postal_code' => '94043',
    ];
    $edit = [];
    $edit['title[0][value]'] = $this->randomMachineName(8);
    foreach ($address as $property => $value) {
      $path = $field_name . '[0][' . $property . ']';
      $edit[$path] = $value;
    }
    $this->submitForm($edit, t('Save'));
    $this->assertSession()->statusCodeEquals(200);
    // Check that the article has been created.
    $node = $this->getNodeByTitle($edit['title[0][value]']);
    $this->assertNotEmpty($node, 'Created article ' . $edit['title[0][value]']);

    // Now remove 'US' from the list of available countries.
    $countries = ['FR', 'BR', 'JP'];
    $edit = [];
    $edit['settings[available_countries][]'] = array_map(function ($country) {
      return $country;
    }, $countries);
    $this->drupalPostForm($this->fieldConfigUrl, $edit, t('Save settings'));

    // Access the article's edit form and confirm the values are unchanged.
    // 'US' should be in the list along with the available countries and should
    // be selected.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldValueEquals($field_name . '[0][recipient]', $address['recipient']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][organization]', $address['organization']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][address_line1]', $address['address_line1']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][locality]', $address['locality']);
    $this->assertOptionSelected($field_name . '[0][administrative_area]', $address['administrative_area']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][postal_code]', $address['postal_code']);
    $this->assertOptionSelected($field_name . '[0][country_code]', $country_code);

    // Test the widget with only one available country.
    // Since the field is required, the country selector should be hidden.
    $countries = ['US'];
    $edit = [];
    $edit['settings[available_countries][]'] = array_map(function ($country) {
      return $country;
    }, $countries);
    $this->drupalPostForm($this->fieldConfigUrl, $edit, t('Save settings'));

    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldNotExists($field_name . '[0][country_code]');
    // Submitting the form should result in no data loss.
    $this->submitForm([], t('Save'));
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldValueEquals($field_name . '[0][recipient]', $address['recipient']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][organization]', $address['organization']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][address_line1]', $address['address_line1']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][locality]', $address['locality']);
    $this->assertOptionSelected($field_name . '[0][administrative_area]', $address['administrative_area']);
    $this->assertSession()->fieldValueEquals($field_name . '[0][postal_code]', $address['postal_code']);
  }

  /**
   * Tests the initial values and available countries alter events.
   */
  function testEvents() {
    $field_name = $this->field->getName();
    // The address_test module is installed here, not in setUp().
    // This way the module's events will not affect other tests.
    self::$modules[] = 'address_test';
    $container = $this->initKernel(\Drupal::request());
    $this->initConfig($container);
    $this->installModulesFromClassProperty($container);
    $this->rebuildAll();
    // Get available countries and initial values from module's event subscriber.
    $subscriber = \Drupal::service('address_test.event_subscriber');
    $availableCountries = array_keys($subscriber->getAvailableCountries());
    $initialValues = $subscriber->getInitialValues();
    // Access the content add form and test the list of countries.
    $this->drupalGet($this->nodeAddUrl);
    $this->assertOptions($field_name . '[0][country_code]', $availableCountries, 'Available countries set in the event subscriber are present in the widget.');
    // Test the values of the fields.
    foreach ($initialValues as $key => $value) {
      if ($value) {
        $name = $field_name . '[0][' . $key . ']';
        $this->assertSession()->fieldValueEquals($name, $value);
      }
    }
    // Remove the address_test module.
    array_pop(self::$modules);
  }

  /**
   * Tests expected and disabled fields.
   */
  function testFields() {
    $field_name = $this->field->getName();
    // Keys are field names from the field instance.
    // Values are corresponding field names from add article form.
    $allFields = [
      'administrativeArea' => $field_name . '[0][administrative_area]',
      'locality' => $field_name . '[0][locality]',
      'dependentLocality' => $field_name . '[0][dependent_locality]',
      'postalCode' => $field_name . '[0][postal_code]',
      'sortingCode' => $field_name . '[0][sorting_code]',
      'addressLine1' => $field_name . '[0][address_line1]',
      'addressLine2' => $field_name . '[0][address_line2]',
      'organization' => $field_name . '[0][organization]',
      'recipient' => $field_name . '[0][recipient]',
    ];
    $allFieldsKeys = array_keys($allFields);

    // US has all fields except sorting code and dependent locality.
    // France has sorting code, and China has dependent locality, so these
    // countries cover all fields.
    $this->drupalGet($this->nodeAddUrl);
    $session = $this->getSession();
    foreach (['US', 'FR', 'CN'] as $country) {
      $addressFormat = $this->addressFormatRepository->get($country);
      $usedFields = $addressFormat->getUsedFields();

      $session->getPage()->fillField($field_name . '[0][country_code]', $country);
      $this->waitForAjaxToFinish();
      // Compare the found fields to the address format.
      // Make one assert instead of many asserts for each field's existence.
      $elements = $this->xpath('//input[starts-with(@name,"' . $field_name . '")] | //select[starts-with(@name,"' . $field_name . '")]');
      $formFields = [];
      foreach ($elements as $key => $element) {
        if ($field = array_search($element->getAttribute('name'), $allFields)) {
          $formFields[] = $field;
        }
      }
      $this->assertFieldValues($usedFields, $formFields, 'Expected fields ' . implode(', ', $usedFields) . ' exists for country ' . $country . ", only found " . implode(', ', $formFields));
    }

    // Disable the recipient and postal code fields.
    $this->drupalGet($this->fieldConfigUrl);
    $disabledFields = ['recipient', 'postalCode'];
    $edit = [];
    foreach ($allFieldsKeys as $field) {
      $edit['settings[fields][' . $field . ']'] = !in_array($field, $disabledFields);
    }
    $this->submitForm($edit, t('Save settings'));
    $this->assertSession()->statusCodeEquals(200);

    // Confirm the absence of disabled fields.
    $this->drupalGet($this->nodeAddUrl);
    $this->assertEmpty((bool) $this->xpath('//input[@name="' . implode('" or @name="', $disabledFields) . '"]'), 'Disabled fields ' . implode(', ', $disabledFields) . ' are absent.');

    // Confirm that creating an address without the disabled fields works.
    $edit = [];
    $edit['title[0][value]'] = $this->randomMachineName(8);

    // Use javascript to fill country_code so other fields can be loaded.
    $session->getPage()->fillField($field_name . '[0][country_code]', 'US');
    $this->waitForAjaxToFinish();

    $edit[$field_name . '[0][organization]'] = 'Some Organization';
    $edit[$field_name . '[0][address_line1]'] = '1098 Alta Ave';
    $edit[$field_name . '[0][address_line2]'] = 'Street 2';
    $edit[$field_name . '[0][locality]'] = 'Mountain View';
    $edit[$field_name . '[0][administrative_area]'] = 'US-CA';
    $this->submitForm($edit, t('Save'));
    $this->assertSession()->statusCodeEquals(200);
    $node = $this->getNodeByTitle($edit['title[0][value]']);
    $this->assertNotEmpty($node, 'Created article ' . $edit['title[0][value]']);
  }

  /**
   * Tests the presence of subdivision dropdowns where expected.
   */
  function testSubdivisions() {
    $field_name = $this->field->getName();
    $session = $this->getSession();
    // Using China since it has predefined subdivisions on all three levels.
    $country = 'CN';
    $administrativeArea = 'CN-13';
    $locality = 'CN-13-2c7460';
    $administrativeAreas = $this->subdivisionRepository->getList($country);
    $localities = $this->subdivisionRepository->getList($country, $administrativeArea);
    $dependentLocalities = $this->subdivisionRepository->getList($country, $locality);
    // Confirm the presence and format of the administrative area dropdown.
    $this->drupalGet($this->nodeAddUrl);
    $session->getPage()->fillField($field_name . '[0][country_code]', $country);
    $this->waitForAjaxToFinish();
    $this->assertOptions($field_name . '[0][administrative_area]', array_keys($administrativeAreas), 'All administrative areas for country ' . $country . ' are present.');

    // Confirm the presence and format of the locality dropdown.
    $session->getPage()->fillField($field_name . '[0][administrative_area]', $administrativeArea);
    $this->waitForAjaxToFinish();
    $this->assertOptionSelected($field_name . '[0][administrative_area]', $administrativeArea, 'Selected administrative area ' . $administrativeAreas[$administrativeArea]);
    $this->assertOptions($field_name . '[0][locality]', array_keys($localities), 'All localities for administrative area ' . $administrativeAreas[$administrativeArea] . ' are present.');

    // Confirm the presence and format of the dependent locality dropdown.
    $session->getPage()->fillField($field_name . '[0][locality]', $locality);
    $this->waitForAjaxToFinish();
    $this->assertOptionSelected($field_name . '[0][locality]', $locality, 'Selected locality ' . $localities[$locality]);
    $this->assertOptions($field_name . '[0][dependent_locality]', array_keys($dependentLocalities), 'All dependent localities for locality ' . $localities[$locality] . ' are present.');
  }

  /**
   * Tests that changing the country clears the expected values.
   */
  function testClearValues() {
    $field_name = $this->field->getName();
    // Create an article with all fields filled.
    $this->drupalGet($this->nodeAddUrl);
    $session = $this->getSession();
    $edit = [];
    $edit['title[0][value]'] = $this->randomMachineName(8);

    // Use javascript to fill country_code so other fields can be loaded.
    $session->getPage()->fillField($field_name . '[0][country_code]', 'US');
    $this->waitForAjaxToFinish();

    $edit[$field_name . '[0][recipient]'] = 'Some Recipient';
    $edit[$field_name . '[0][organization]'] = 'Some Organization';
    $edit[$field_name . '[0][address_line1]'] = '1098 Alta Ave';
    $edit[$field_name . '[0][address_line2]'] = 'Street 2';
    $edit[$field_name . '[0][locality]'] = 'Mountain View';
    $edit[$field_name . '[0][administrative_area]'] = 'US-CA';
    $edit[$field_name . '[0][postal_code]'] = '94043';
    $this->submitForm($edit, t('Save'));
    $this->assertSession()->statusCodeEquals(200);
    $node = $this->getNodeByTitle($edit['title[0][value]']);

    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldValueEquals($field_name . '[0][country_code]', 'US');
    $this->assertSession()->fieldValueEquals($field_name . '[0][administrative_area]', 'US-CA');
    $this->assertSession()->fieldValueEquals($field_name . '[0][locality]', 'Mountain View');
    $this->assertSession()->fieldValueEquals($field_name . '[0][postal_code]', '94043');

    // Now change the country to China, subdivision fields should be cleared.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $session->getPage()->fillField($field_name . '[0][country_code]', 'CN');
    $this->waitForAjaxToFinish();
    $this->submitForm([], t('Save'));
    $this->assertSession()->statusCodeEquals(200);
    // Check that values are cleared.
    $this->assertSession()->fieldValueEquals($field_name . '[0][country_code]', 'CN');
    $this->assertSession()->fieldValueEquals($field_name . '[0][administrative_area]', '');
    $this->assertSession()->fieldValueEquals($field_name . '[0][locality]', '');
    $this->assertSession()->fieldValueEquals($field_name . '[0][dependent_locality]', '');
    $this->assertSession()->fieldValueEquals($field_name . '[0][postal_code]', '');
  }

  /**
   * Asserts that a select field has all of the provided options.
   *
   * Core only has assertOption(), this helper decreases the number of needed
   * assertions.
   *
   * @param string $id
   *   ID of select field to assert.
   * @param array $options
   *   Options to assert.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use \Drupal\Component\Utility\SafeMarkup::format() to embed
   *   variables in the message text, not t(). If left blank, a default message
   *   will be displayed.
   */
  protected function assertOptions($id, $options, $message) {
    $elements = $this->xpath('//select[@name="' . $id . '"]/option');
    $foundOptions = [];
    foreach ($elements as $element) {
      if ($option = $element->getValue()) {
        $foundOptions[] = $option;
      }
    }
    $this->assertFieldValues($foundOptions, $options, $message);
  }


  /**
   * Asserts that a select field has a selected option.
   *
   * @param string $id
   *   ID of select field to assert.
   * @param string $option
   *   Option to assert.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use \Drupal\Component\Utility\SafeMarkup::format() to embed
   *   variables in the message text, not t(). If left blank, a default message
   *   will be displayed.
   */
  protected function assertOptionSelected($id, $option, $message = '') {
    $elements = $this->xpath('//select[@name=:id]//option[@value=:option]', array(':id' => $id, ':option' => $option));
    foreach ($elements as $element) {
      $this->assertNotEmpty($element->isSelected(), $message ? $message : new FormattableMarkup('Option @option for field @id is selected.', array('@option' => $option, '@id' => $id)));
    }
  }

  /**
   * Asserts that the passed field values are correct.
   *
   * Ignores differences in ordering.
   *
   * @param array $fieldValues
   *   The field values.
   * @param array $expectedValues
   *   The expected values.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use \Drupal\Component\Utility\SafeMarkup::format() to embed
   *   variables in the message text, not t(). If left blank, a default message
   *   will be displayed.
   */
  protected function assertFieldValues(array $fieldValues, array $expectedValues, $message = '') {
    $valid = TRUE;
    if (count($fieldValues) == count($expectedValues)) {
      foreach ($expectedValues as $value) {
        if (!in_array($value, $fieldValues)) {
          $valid = FALSE;
          break;
        }
      }
    }
    else {
      $valid = FALSE;
    }

    $this->assertNotEmpty($valid, $message);
  }

  /**
   * Waits for jQuery to become active and animations to complete.
   */
  protected function waitForAjaxToFinish() {
    $condition = "(0 === jQuery.active && 0 === jQuery(':animated').length)";
    $this->assertJsCondition($condition, 10000);
  }

}
