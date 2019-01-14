<?php
/**
 * @file
 * Contains \Drupal\ddbgo_cj\Plugin\QueueWorker\KweQueueWorker.
 */

namespace Drupal\ddbgo_cj\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;

/**
 * KWE Queue Worker which is doing the update work.
 *
 * @QueueWorker(
 *   id = "kwe_queue_worker",
 *   title = @Translation("KWE Queue Worker which in updating one specific node of type KWE."),
 *   cron = {"time" = 60}
 * )
 */
class KweQueueWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($nid) {

    $node = \Drupal\node\Entity\Node::load($nid);
    // get DDB-URI
    if (!isset($node) || !$node->hasField("field_ddburi") || empty($node->get("field_ddburi")->value)) {
      return;
    }

    // FROM: https://www.deutsche-digitale-bibliothek.de/organization/2Q37XY5KXJNJE5MV6SWP3UKKZ6RSBLK5
    // TO: https://api.deutsche-digitale-bibliothek.de/items/2Q37XY5KXJNJE5MV6SWP3UKKZ6RSBLK5/source/record

    $url = str_replace("https://www.deutsche-digitale-bibliothek.de/organization/",
        "https://api.deutsche-digitale-bibliothek.de/items/",
        $node->get('field_ddburi')->value)
      . "/source/record";

    $xml = $this->loadxmlfromurl($nid, trim($url));
    // check if we got data from xml
    if (!isset($xml) || $xml === FALSE) {
      return;
    }
    $results = $this->parseddborg($xml);

    // indicator if anything has been changed
    $any_changes = FALSE;

    // do the updates
    // ==========================
    if ($node->hasField('title') && isset($results['displayName']) && strcmp($node->get('title')->value, $results['displayName']) !== 0) {
      $node->set('title', $results['displayName']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_ddb_id') && isset($results['id']) && strcmp($node->get('field_ddb_id')->value, $results['id']) !== 0) {
      $node->set('field_ddb_id', $results['id']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_isil') && isset($results['pid']) && strcmp($node->get('field_isil')->value, $results['pid']) !== 0) {
      $node->set('field_isil', $results['pid']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_kurzname') && isset($results['abbreviation']) && strcmp($node->get('field_kurzname')->value, $results['abbreviation']) !== 0) {
      $node->set('field_kurzname', $results['abbreviation']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_plz') && isset($results['postalCode']) && strcmp($node->get('field_plz')->value, $results['postalCode']) !== 0) {
      $node->set('field_plz', $results['postalCode']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_stadt') && isset($results['city']) && strcmp($node->get('field_stadt')->value, $results['city']) !== 0) {
      $node->set('field_stadt', $results['city']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_telefonnummer') && isset($results['telephone']) && strcmp($node->get('field_telefonnummer')->value, $results['telephone']) !== 0) {
      $node->set('field_telefonnummer', $results['telephone']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_email') && isset($results['email']) && strcmp($node->get('field_email')->value, $results['email']) !== 0) {
      $node->set('field_email', $results['email']);
      $any_changes = TRUE;
    }

    if ($node->hasField('field_url') && isset($results['url']) && strcmp($node->get('field_url')->uri, $results['url']) !== 0) {
      $node->set('field_url', ['uri' => $results['url']]);
      $any_changes = TRUE;
    }

    // street with no.
    if ($node->hasField('field_strasse') && isset($results['street'])) {

      $street = $results['street'];
      // add street no. if there's any
      if (isset($results['houseIdentifier'])) {
        $street = $street . " " . $results['houseIdentifier'];
      }

      if (strcmp($node->get('field_strasse')->value, $street) !== 0) {
        $node->set('field_strasse', $street);
        $any_changes = TRUE;
      }
    }

    // Bundesland: state - field_bundesland
    // get term of Bundesland-URI in Drupal
    if ($node->hasField('field_bundesland') && isset($results['state'])) {
      $term_nids = \Drupal::entityQuery('taxonomy_term')
        ->condition('vid', 'bundesland')
        ->condition('field_uri', $results['state'], '=')
        ->addMetaData('account', \Drupal\user\Entity\User::load(1))
        ->execute();

      // taxonomy term found?
      if (count($term_nids) > 0) {
        $term_id = current($term_nids);
        if (isset($term_id) && $term_id !== $node->get('field_bundesland')->target_id) {
          $node->set('field_bundesland', ['target_id' => $term_id]);
          $any_changes = TRUE;
        }
      }
    }

    // Land: $results['country'] - field_land
    // get term of Land-URI in Drupal
    if ($node->hasField('field_land') && isset($results['country'])) {
      $term_nids = \Drupal::entityQuery('taxonomy_term')
        ->condition('vid', 'land')
        ->condition('field_uri', $results['country'], '=')
        ->addMetaData('account', \Drupal\user\Entity\User::load(1))
        ->execute();

      // taxonomy term found?
      if (count($term_nids) > 0) {
        $term_id = current($term_nids);
        if (isset($term_id) && $term_id !== $node->get('field_land')->target_id) {
          $node->set('field_land', ['target_id' => $term_id]);
          $any_changes = TRUE;
        }
      }
    }

    // Sparte: $results['sector']	- field_sparte
    if ($node->hasField('field_sparte') && isset($results['sector'])) {
      $term_nids = \Drupal::entityQuery('taxonomy_term')
        ->condition('vid', 'kultursparte')
        ->condition('field_uri', $results['sector'], '=')
        ->addMetaData('account', \Drupal\user\Entity\User::load(1))
        ->execute();

      // taxonomy term found?
      if (count($term_nids) > 0) {
        $term_id = current($term_nids);
        if (isset($term_id) && $term_id !== $node->get('field_sparte')->target_id) {
          $node->set('field_sparte', ['target_id' => $term_id]);
          $any_changes = TRUE;
        }
      }
    }
    // ==========================

    if ($any_changes) {
      if ($node->getEntityType()->isRevisionable()) {
        $node->setNewRevision();
        $node->setRevisionLogMessage("Automatic Update from " . $url);
      }
      $node->save();
      \Drupal::logger('ddbgo_cj')->info("Node " . $nid . " was successfully updated from " . $url);
    } else {
      \Drupal::logger('ddbgo_cj')->info("Node " . $nid . " is already up-to-date.");
    }
  }

  /**
   * Load data from an URL and return SimpleXMLElement.
   *
   * @param $nid Needed for logging only
   * @param $url URL to download
   *
   * @return \SimpleXMLElement Parsed XML document
   */
  private function loadxmlfromurl($nid = '<unknown>', $url) {
    // check if url exists
    $headers = get_headers($url);
    if (strpos($headers[0], '404') === FALSE) {
      try {
        $opts = [
          "http" => [
            "method" => "GET",
            "header" => "Accept: application/xml",
            'timeout' => 3.0, // 3 sec. timeout
          ],
        ];
        // get stream as XML
        $context = stream_context_create($opts);
        // parse xml
        return simplexml_load_string(file_get_contents($url, FALSE, $context));
      } catch (Exception $e) {
        \Drupal::logger('ddbgo_cj')
          ->error("Error while download information from " . $url . ". Cannot update node " . $nid . ". " . $e->getMessage());
      }
    }
    else {
      \Drupal::logger('ddbgo_cj')
        ->warning($url . " seems to be NOT a valid DDB URI. Cannot update node " . $nid);
    }
  }

  /**
   * Parse DDB-Organisation with xPath from XML document.
   * e.g.
   * https://api.deutsche-digitale-bibliothek.de/items/2Q37XY5KXJNJE5MV6SWP3UKKZ6RSBLK5/source/record
   *
   * @param $xml Parsed XML document. See ddbgo_cj_loadxmlfromurl().
   *
   * @return array Key/Value array with following keys: id, displayName, pid,
   * abbreviation, street, houseIdentifier, postalCode, city, state, country,
   * sector, telephone, email, url.
   */
  private function parseddborg($xml) {
    $results = [];

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="id"]')) && isset($result[0])) {
      $results['id'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="displayName"][@lang="deu"]')) && isset($result[0])) {
      $results['displayName'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="pid"]')) && isset($result[0])) {
      $results['pid'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="abbreviation"][@lang="deu"]')) && isset($result[0])) {
      $results['abbreviation'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="street"]')) && isset($result[0])) {
      $results['street'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="houseIdentifier"]')) && isset($result[0])) {
      $results['houseIdentifier'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="postalCode"]')) && isset($result[0])) {
      $results['postalCode'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="city"]/*[local-name()="label"][@lang="deu"]')) && isset($result[0])) {
      $results['city'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="state"]/@uri')) && isset($result[0])) {
      $results['state'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="address"]/*[local-name()="country"]/@uri')) && isset($result[0])) {
      $results['country'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="sector"]')) && isset($result[0])) {
      $results['sector'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="telephone"]')) && isset($result[0])) {
      $results['telephone'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="email"]')) && isset($result[0])) {
      $results['email'] = trim($result[0]);
    }

    if (($result = $xml->xpath('/*[local-name()="organization"]/*[local-name()="url"]')) && isset($result[0])) {
      $results['url'] = trim($result[0]);
    }

    return $results;
  }

}