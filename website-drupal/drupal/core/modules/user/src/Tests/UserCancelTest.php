<?php

namespace Drupal\user\Tests;

use Drupal\comment\Tests\CommentTestTrait;
use Drupal\simpletest\WebTestBase;
use Drupal\comment\CommentInterface;
use Drupal\comment\Entity\Comment;
use Drupal\user\Entity\User;

/**
 * Ensure that account cancellation methods work as expected.
 *
 * @group user
 */
class UserCancelTest extends WebTestBase {

  use CommentTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('node', 'comment');

  protected function setUp() {
    parent::setUp();

    $this->drupalCreateContentType(array('type' => 'page', 'name' => 'Basic page'));
  }

  /**
   * Attempt to cancel account without permission.
   */
  function testUserCancelWithoutPermission() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    // Create a user.
    $account = $this->drupalCreateUser(array());
    $this->drupalLogin($account);
    // Load a real user object.
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());

    // Create a node.
    $node = $this->drupalCreateNode(array('uid' => $account->id()));

    // Attempt to cancel account.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->assertNoRaw(t('Cancel account'), 'No cancel account button displayed.');

    // Attempt bogus account cancellation request confirmation.
    $timestamp = $account->getLastLoginTime();
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$timestamp/" . user_pass_rehash($account, $timestamp));
    $this->assertResponse(403, 'Bogus cancelling request rejected.');
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());
    $this->assertTrue($account->isActive(), 'User account was not canceled.');

    // Confirm user's content has not been altered.
    $node_storage->resetCache(array($node->id()));
    $test_node = $node_storage->load($node->id());
    $this->assertTrue(($test_node->getOwnerId() == $account->id() && $test_node->isPublished()), 'Node of the user has not been altered.');
  }

  /**
   * Test ability to change the permission for canceling users.
   */
  public function testUserCancelChangePermission() {
    \Drupal::service('module_installer')->install(array('user_form_test'));
    \Drupal::service('router.builder')->rebuild();
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();

    // Create a regular user.
    $account = $this->drupalCreateUser(array());

    $admin_user = $this->drupalCreateUser(array('cancel other accounts'));
    $this->drupalLogin($admin_user);

    // Delete regular user.
    $this->drupalPostForm('user_form_test_cancel/' . $account->id(), array(), t('Cancel account'));

    // Confirm deletion.
    $this->assertRaw(t('%name has been deleted.', array('%name' => $account->getUsername())), 'User deleted.');
    $this->assertFalse(User::load($account->id()), 'User is not found in the database.');
  }

  /**
   * Tests that user account for uid 1 cannot be cancelled.
   *
   * This should never be possible, or the site owner would become unable to
   * administer the site.
   */
  function testUserCancelUid1() {
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    \Drupal::service('module_installer')->install(array('views'));
    \Drupal::service('router.builder')->rebuild();
    // Update uid 1's name and password to we know it.
    $password = user_password();
    $account = array(
      'name' => 'user1',
      'pass' => $this->container->get('password')->hash(trim($password)),
    );
    // We cannot use $account->save() here, because this would result in the
    // password being hashed again.
    db_update('users_field_data')
      ->fields($account)
      ->condition('uid', 1)
      ->execute();

    // Reload and log in uid 1.
    $user_storage->resetCache(array(1));
    $user1 = $user_storage->load(1);
    $user1->pass_raw = $password;

    // Try to cancel uid 1's account with a different user.
    $admin_user = $this->drupalCreateUser(array('administer users'));
    $this->drupalLogin($admin_user);
    $edit = array(
      'action' => 'user_cancel_user_action',
      'user_bulk_form[0]' => TRUE,
    );
    $this->drupalPostForm('admin/people', $edit, t('Apply to selected items'));

    // Verify that uid 1's account was not cancelled.
    $user_storage->resetCache(array(1));
    $user1 = $user_storage->load(1);
    $this->assertTrue($user1->isActive(), 'User #1 still exists and is not blocked.');
  }

  /**
   * Attempt invalid account cancellations.
   */
  function testUserCancelInvalid() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    // Create a user.
    $account = $this->drupalCreateUser(array('cancel account'));
    $this->drupalLogin($account);
    // Load a real user object.
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());

    // Create a node.
    $node = $this->drupalCreateNode(array('uid' => $account->id()));

    // Attempt to cancel account.
    $this->drupalPostForm('user/' . $account->id() . '/edit', NULL, t('Cancel account'));

    // Confirm account cancellation.
    $timestamp = time();
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('A confirmation request to cancel your account has been sent to your email address.'), 'Account cancellation request mailed message displayed.');

    // Attempt bogus account cancellation request confirmation.
    $bogus_timestamp = $timestamp + 60;
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$bogus_timestamp/" . user_pass_rehash($account, $bogus_timestamp));
    $this->assertText(t('You have tried to use an account cancellation link that has expired. Please request a new one using the form below.'), 'Bogus cancelling request rejected.');
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());
    $this->assertTrue($account->isActive(), 'User account was not canceled.');

    // Attempt expired account cancellation request confirmation.
    $bogus_timestamp = $timestamp - 86400 - 60;
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$bogus_timestamp/" . user_pass_rehash($account, $bogus_timestamp));
    $this->assertText(t('You have tried to use an account cancellation link that has expired. Please request a new one using the form below.'), 'Expired cancel account request rejected.');
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());
    $this->assertTrue($account->isActive(), 'User account was not canceled.');

    // Confirm user's content has not been altered.
    $node_storage->resetCache(array($node->id()));
    $test_node = $node_storage->load($node->id());
    $this->assertTrue(($test_node->getOwnerId() == $account->id() && $test_node->isPublished()), 'Node of the user has not been altered.');
  }

  /**
   * Disable account and keep all content.
   */
  function testUserBlock() {
    $this->config('user.settings')->set('cancel_method', 'user_cancel_block')->save();
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    // Create a user.
    $web_user = $this->drupalCreateUser(array('cancel account'));
    $this->drupalLogin($web_user);

    // Load a real user object.
    $user_storage->resetCache(array($web_user->id()));
    $account = $user_storage->load($web_user->id());

    // Attempt to cancel account.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('Are you sure you want to cancel your account?'), 'Confirmation form to cancel account displayed.');
    $this->assertText(t('Your account will be blocked and you will no longer be able to log in. All of your content will remain attributed to your username.'), 'Informs that all content will be remain as is.');
    $this->assertNoText(t('Select the method to cancel the account above.'), 'Does not allow user to select account cancellation method.');

    // Confirm account cancellation.
    $timestamp = time();

    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('A confirmation request to cancel your account has been sent to your email address.'), 'Account cancellation request mailed message displayed.');

    // Confirm account cancellation request.
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$timestamp/" . user_pass_rehash($account, $timestamp));
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());
    $this->assertTrue($account->isBlocked(), 'User has been blocked.');

    // Confirm that the confirmation message made it through to the end user.
    $this->assertRaw(t('%name has been disabled.', array('%name' => $account->getUsername())), "Confirmation message displayed to user.");
  }

  /**
   * Disable account and unpublish all content.
   */
  function testUserBlockUnpublish() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $this->config('user.settings')->set('cancel_method', 'user_cancel_block_unpublish')->save();
    // Create comment field on page.
    $this->addDefaultCommentField('node', 'page');
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    // Create a user.
    $account = $this->drupalCreateUser(array('cancel account'));
    $this->drupalLogin($account);
    // Load a real user object.
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());

    // Create a node with two revisions.
    $node = $this->drupalCreateNode(array('uid' => $account->id()));
    $settings = get_object_vars($node);
    $settings['revision'] = 1;
    $node = $this->drupalCreateNode($settings);

    // Add a comment to the page.
    $comment_subject = $this->randomMachineName(8);
    $comment_body = $this->randomMachineName(8);
    $comment = Comment::create(array(
      'subject' => $comment_subject,
      'comment_body' => $comment_body,
      'entity_id' => $node->id(),
      'entity_type' => 'node',
      'field_name' => 'comment',
      'status' => CommentInterface::PUBLISHED,
      'uid' => $account->id(),
    ));
    $comment->save();

    // Attempt to cancel account.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('Are you sure you want to cancel your account?'), 'Confirmation form to cancel account displayed.');
    $this->assertText(t('Your account will be blocked and you will no longer be able to log in. All of your content will be hidden from everyone but administrators.'), 'Informs that all content will be unpublished.');

    // Confirm account cancellation.
    $timestamp = time();
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('A confirmation request to cancel your account has been sent to your email address.'), 'Account cancellation request mailed message displayed.');

    // Confirm account cancellation request.
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$timestamp/" . user_pass_rehash($account, $timestamp));
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());
    $this->assertTrue($account->isBlocked(), 'User has been blocked.');

    // Confirm user's content has been unpublished.
    $node_storage->resetCache(array($node->id()));
    $test_node = $node_storage->load($node->id());
    $this->assertFalse($test_node->isPublished(), 'Node of the user has been unpublished.');
    $test_node = node_revision_load($node->getRevisionId());
    $this->assertFalse($test_node->isPublished(), 'Node revision of the user has been unpublished.');

    $storage = \Drupal::entityManager()->getStorage('comment');
    $storage->resetCache(array($comment->id()));
    $comment = $storage->load($comment->id());
    $this->assertFalse($comment->isPublished(), 'Comment of the user has been unpublished.');

    // Confirm that the confirmation message made it through to the end user.
    $this->assertRaw(t('%name has been disabled.', array('%name' => $account->getUsername())), "Confirmation message displayed to user.");
  }

  /**
   * Delete account and anonymize all content.
   */
  function testUserAnonymize() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();
    // Create comment field on page.
    $this->addDefaultCommentField('node', 'page');
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    // Create a user.
    $account = $this->drupalCreateUser(array('cancel account'));
    $this->drupalLogin($account);
    // Load a real user object.
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());

    // Create a simple node.
    $node = $this->drupalCreateNode(array('uid' => $account->id()));

    // Add a comment to the page.
    $comment_subject = $this->randomMachineName(8);
    $comment_body = $this->randomMachineName(8);
    $comment = Comment::create(array(
      'subject' => $comment_subject,
      'comment_body' => $comment_body,
      'entity_id' => $node->id(),
      'entity_type' => 'node',
      'field_name' => 'comment',
      'status' => CommentInterface::PUBLISHED,
      'uid' => $account->id(),
    ));
    $comment->save();

    // Create a node with two revisions, the initial one belonging to the
    // cancelling user.
    $revision_node = $this->drupalCreateNode(array('uid' => $account->id()));
    $revision = $revision_node->getRevisionId();
    $settings = get_object_vars($revision_node);
    $settings['revision'] = 1;
    $settings['uid'] = 1; // Set new/current revision to someone else.
    $revision_node = $this->drupalCreateNode($settings);

    // Attempt to cancel account.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('Are you sure you want to cancel your account?'), 'Confirmation form to cancel account displayed.');
    $this->assertRaw(t('Your account will be removed and all account information deleted. All of your content will be assigned to the %anonymous-name user.', array('%anonymous-name' => $this->config('user.settings')->get('anonymous'))), 'Informs that all content will be attributed to anonymous account.');

    // Confirm account cancellation.
    $timestamp = time();
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('A confirmation request to cancel your account has been sent to your email address.'), 'Account cancellation request mailed message displayed.');

    // Confirm account cancellation request.
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$timestamp/" . user_pass_rehash($account, $timestamp));
    $user_storage->resetCache(array($account->id()));
    $this->assertFalse($user_storage->load($account->id()), 'User is not found in the database.');

    // Confirm that user's content has been attributed to anonymous user.
    $anonymous_user = User::getAnonymousUser();
    $node_storage->resetCache(array($node->id()));
    $test_node = $node_storage->load($node->id());
    $this->assertTrue(($test_node->getOwnerId() == 0 && $test_node->isPublished()), 'Node of the user has been attributed to anonymous user.');
    $test_node = node_revision_load($revision, TRUE);
    $this->assertTrue(($test_node->getRevisionUser()->id() == 0 && $test_node->isPublished()), 'Node revision of the user has been attributed to anonymous user.');
    $node_storage->resetCache(array($revision_node->id()));
    $test_node = $node_storage->load($revision_node->id());
    $this->assertTrue(($test_node->getOwnerId() != 0 && $test_node->isPublished()), "Current revision of the user's node was not attributed to anonymous user.");

    $storage = \Drupal::entityManager()->getStorage('comment');
    $storage->resetCache(array($comment->id()));
    $test_comment = $storage->load($comment->id());
    $this->assertTrue(($test_comment->getOwnerId() == 0 && $test_comment->isPublished()), 'Comment of the user has been attributed to anonymous user.');
    $this->assertEqual($test_comment->getAuthorName(), $anonymous_user->getDisplayName(), 'Comment of the user has been attributed to anonymous user name.');

    // Confirm that the confirmation message made it through to the end user.
    $this->assertRaw(t('%name has been deleted.', array('%name' => $account->getUsername())), "Confirmation message displayed to user.");
  }

  /**
   * Delete account and anonymize all content using a batch process.
   */
  public function testUserAnonymizeBatch() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    // Create a user.
    $account = $this->drupalCreateUser(array('cancel account'));
    $this->drupalLogin($account);
    // Load a real user object.
    $user_storage->resetCache([$account->id()]);
    $account = $user_storage->load($account->id());

    // Create 11 nodes in order to trigger batch processing in
    // node_mass_update().
    $nodes = [];
    for ($i = 0; $i < 11; $i++) {
      $node = $this->drupalCreateNode(['uid' => $account->id()]);
      $nodes[$node->id()] = $node;
    }

    // Attempt to cancel account.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('Are you sure you want to cancel your account?'), 'Confirmation form to cancel account displayed.');
    $this->assertRaw(t('Your account will be removed and all account information deleted. All of your content will be assigned to the %anonymous-name user.', array('%anonymous-name' => $this->config('user.settings')->get('anonymous'))), 'Informs that all content will be attributed to anonymous account.');

    // Confirm account cancellation.
    $timestamp = time();
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('A confirmation request to cancel your account has been sent to your email address.'), 'Account cancellation request mailed message displayed.');

    // Confirm account cancellation request.
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$timestamp/" . user_pass_rehash($account, $timestamp));
    $user_storage->resetCache([$account->id()]);
    $this->assertFalse($user_storage->load($account->id()), 'User is not found in the database.');

    // Confirm that user's content has been attributed to anonymous user.
    $node_storage->resetCache(array_keys($nodes));
    $test_nodes = $node_storage->loadMultiple(array_keys($nodes));
    foreach ($test_nodes as $test_node) {
      $this->assertTrue(($test_node->getOwnerId() == 0 && $test_node->isPublished()), 'Node ' . $test_node->id() . ' of the user has been attributed to anonymous user.');
    }
  }

  /**
   * Delete account and remove all content.
   */
  function testUserDelete() {
    $node_storage = $this->container->get('entity.manager')->getStorage('node');
    $this->config('user.settings')->set('cancel_method', 'user_cancel_delete')->save();
    \Drupal::service('module_installer')->install(array('comment'));
    $this->resetAll();
    $this->addDefaultCommentField('node', 'page');
    $user_storage = $this->container->get('entity.manager')->getStorage('user');

    // Create a user.
    $account = $this->drupalCreateUser(array('cancel account', 'post comments', 'skip comment approval'));
    $this->drupalLogin($account);
    // Load a real user object.
    $user_storage->resetCache(array($account->id()));
    $account = $user_storage->load($account->id());

    // Create a simple node.
    $node = $this->drupalCreateNode(array('uid' => $account->id()));

    // Create comment.
    $edit = array();
    $edit['subject[0][value]'] = $this->randomMachineName(8);
    $edit['comment_body[0][value]'] = $this->randomMachineName(16);

    $this->drupalPostForm('comment/reply/node/' . $node->id() . '/comment', $edit, t('Preview'));
    $this->drupalPostForm(NULL, array(), t('Save'));
    $this->assertText(t('Your comment has been posted.'));
    $comments = entity_load_multiple_by_properties('comment', array('subject' => $edit['subject[0][value]']));
    $comment = reset($comments);
    $this->assertTrue($comment->id(), 'Comment found.');

    // Create a node with two revisions, the initial one belonging to the
    // cancelling user.
    $revision_node = $this->drupalCreateNode(array('uid' => $account->id()));
    $revision = $revision_node->getRevisionId();
    $settings = get_object_vars($revision_node);
    $settings['revision'] = 1;
    $settings['uid'] = 1; // Set new/current revision to someone else.
    $revision_node = $this->drupalCreateNode($settings);

    // Attempt to cancel account.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('Are you sure you want to cancel your account?'), 'Confirmation form to cancel account displayed.');
    $this->assertText(t('Your account will be removed and all account information deleted. All of your content will also be deleted.'), 'Informs that all content will be deleted.');

    // Confirm account cancellation.
    $timestamp = time();
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertText(t('A confirmation request to cancel your account has been sent to your email address.'), 'Account cancellation request mailed message displayed.');

    // Confirm account cancellation request.
    $this->drupalGet("user/" . $account->id() . "/cancel/confirm/$timestamp/" . user_pass_rehash($account, $timestamp));
    $user_storage->resetCache(array($account->id()));
    $this->assertFalse($user_storage->load($account->id()), 'User is not found in the database.');

    // Confirm that user's content has been deleted.
    $node_storage->resetCache(array($node->id()));
    $this->assertFalse($node_storage->load($node->id()), 'Node of the user has been deleted.');
    $this->assertFalse(node_revision_load($revision), 'Node revision of the user has been deleted.');
    $node_storage->resetCache(array($revision_node->id()));
    $this->assertTrue($node_storage->load($revision_node->id()), "Current revision of the user's node was not deleted.");
    \Drupal::entityManager()->getStorage('comment')->resetCache(array($comment->id()));
    $this->assertFalse(Comment::load($comment->id()), 'Comment of the user has been deleted.');

    // Confirm that the confirmation message made it through to the end user.
    $this->assertRaw(t('%name has been deleted.', array('%name' => $account->getUsername())), "Confirmation message displayed to user.");
  }

  /**
   * Create an administrative user and delete another user.
   */
  function testUserCancelByAdmin() {
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();

    // Create a regular user.
    $account = $this->drupalCreateUser(array());

    // Create administrative user.
    $admin_user = $this->drupalCreateUser(array('administer users'));
    $this->drupalLogin($admin_user);

    // Delete regular user.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertRaw(t('Are you sure you want to cancel the account %name?', array('%name' => $account->getUsername())), 'Confirmation form to cancel account displayed.');
    $this->assertText(t('Select the method to cancel the account above.'), 'Allows to select account cancellation method.');

    // Confirm deletion.
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertRaw(t('%name has been deleted.', array('%name' => $account->getUsername())), 'User deleted.');
    $this->assertFalse(User::load($account->id()), 'User is not found in the database.');
  }

  /**
   * Tests deletion of a user account without an email address.
   */
  function testUserWithoutEmailCancelByAdmin() {
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();

    // Create a regular user.
    $account = $this->drupalCreateUser(array());
    // This user has no email address.
    $account->mail = '';
    $account->save();

    // Create administrative user.
    $admin_user = $this->drupalCreateUser(array('administer users'));
    $this->drupalLogin($admin_user);

    // Delete regular user without email address.
    $this->drupalGet('user/' . $account->id() . '/edit');
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertRaw(t('Are you sure you want to cancel the account %name?', array('%name' => $account->getUsername())), 'Confirmation form to cancel account displayed.');
    $this->assertText(t('Select the method to cancel the account above.'), 'Allows to select account cancellation method.');

    // Confirm deletion.
    $this->drupalPostForm(NULL, NULL, t('Cancel account'));
    $this->assertRaw(t('%name has been deleted.', array('%name' => $account->getUsername())), 'User deleted.');
    $this->assertFalse(User::load($account->id()), 'User is not found in the database.');
  }

  /**
   * Create an administrative user and mass-delete other users.
   */
  function testMassUserCancelByAdmin() {
    \Drupal::service('module_installer')->install(array('views'));
    \Drupal::service('router.builder')->rebuild();
    $this->config('user.settings')->set('cancel_method', 'user_cancel_reassign')->save();
    $user_storage = $this->container->get('entity.manager')->getStorage('user');
    // Enable account cancellation notification.
    $this->config('user.settings')->set('notify.status_canceled', TRUE)->save();

    // Create administrative user.
    $admin_user = $this->drupalCreateUser(array('administer users'));
    $this->drupalLogin($admin_user);

    // Create some users.
    $users = array();
    for ($i = 0; $i < 3; $i++) {
      $account = $this->drupalCreateUser(array());
      $users[$account->id()] = $account;
    }

    // Cancel user accounts, including own one.
    $edit = array();
    $edit['action'] = 'user_cancel_user_action';
    for ($i = 0; $i <= 4; $i++) {
      $edit['user_bulk_form[' . $i . ']'] = TRUE;
    }
    $this->drupalPostForm('admin/people', $edit, t('Apply to selected items'));
    $this->assertText(t('Are you sure you want to cancel these user accounts?'), 'Confirmation form to cancel accounts displayed.');
    $this->assertText(t('When cancelling these accounts'), 'Allows to select account cancellation method.');
    $this->assertText(t('Require email confirmation to cancel account'), 'Allows to send confirmation mail.');
    $this->assertText(t('Notify user when account is canceled'), 'Allows to send notification mail.');

    // Confirm deletion.
    $this->drupalPostForm(NULL, NULL, t('Cancel accounts'));
    $status = TRUE;
    foreach ($users as $account) {
      $status = $status && (strpos($this->content, $account->getUsername() . '</em> has been deleted.') !== FALSE);
      $user_storage->resetCache(array($account->id()));
      $status = $status && !$user_storage->load($account->id());
    }
    $this->assertTrue($status, 'Users deleted and not found in the database.');

    // Ensure that admin account was not cancelled.
    $this->assertText(t('A confirmation request to cancel your account has been sent to your email address.'), 'Account cancellation request mailed message displayed.');
    $admin_user = $user_storage->load($admin_user->id());
    $this->assertTrue($admin_user->isActive(), 'Administrative user is found in the database and enabled.');

    // Verify that uid 1's account was not cancelled.
    $user_storage->resetCache(array(1));
    $user1 = $user_storage->load(1);
    $this->assertTrue($user1->isActive(), 'User #1 still exists and is not blocked.');
  }

}
