<?php

namespace Drupal\seb\Plugin\Block;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Scheduled Entity block.
 *
 * @Block(
 *   id = "seb_entity_block",
 *   admin_label = @Translation("Scheduled Entity block"),
 *   deriver = "Drupal\seb\Plugin\Derivative\EntityBlock"
 * )
 */
class EntityBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The number of times this block allows rendering the same entity.
   *
   * @var int
   */
  const RECURSIVE_RENDER_LIMIT = 3;

  /**
   * The name of our entity type.
   *
   * @var string
   */
  protected $entityTypeName;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  public $entityTypeManager;

  /**
   * The entity storage for our entity type.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityStorage;

  /**
   * The view builder for our entity type.
   *
   * @var \Drupal\Core\Entity\EntityViewBuilderInterface
   */
  protected $entityViewBuilder;

  /**
   * An array of view mode labels, keyed by the display mode ID.
   *
   * @var array
   */
  protected $viewModeOptions;

  /**
   * An array of counters for the recursive rendering protection.
   *
   * @var array
   */
  protected static $recursiveRenderDepth = [];

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * An array of days.
   *
   * @var array
   */
  protected $days = [
    'monday' => 'Monday',
    'tuesday' => 'Tuesday',
    'wednesday' => 'Wednesday',
    'thursday' => 'Thursday',
    'friday' => 'Friday',
    'saturday' => 'Saturday',
    'sunday' => 'Sunday',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entityTypeManager, EntityDisplayRepositoryInterface $entityDisplayRepository, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    // Determine what entity type we are referring to.
    $this->entityTypeName = $this->getDerivativeId();

    // Load various utilities related to our entity type.
    $this->entityTypeManager = $entityTypeManager;
    $this->entityStorage = $entityTypeManager->getStorage($this->entityTypeName);

    // Panelizer replaces the view_builder handler, but we want to use the
    // original which has been moved to fallback_view_builder.
    if ($entityTypeManager->hasHandler($this->entityTypeName, 'fallback_view_builder')) {
      $this->entityViewBuilder = $entityTypeManager->getHandler($this->entityTypeName, 'fallback_view_builder');
    }
    else {
      $this->entityViewBuilder = $entityTypeManager->getHandler($this->entityTypeName, 'view_builder');
    }

    $this->viewModeOptions = $entityDisplayRepository->getViewModeOptions($this->entityTypeName);
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->configuration;

    $form['entity'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Entity'),
      '#target_type' => $this->entityTypeName,
      '#required' => TRUE,
      '#maxlength' => 1024,
    ];

    if (isset($config['entity'])) {
      if ($entity = $this->entityStorage->load($config['entity'])) {
        $form['entity']['#default_value'] = $entity;
      }
    }

    $view_mode = $config['view_mode'] ?? NULL;

    $form['view_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode'),
      '#options' => $this->viewModeOptions,
      '#default_value' => $view_mode,
    ];

    $form['schedule'] = $this->getScheduleField($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Hide default block form fields that are undesired in this case.
    $form['admin_label']['#access'] = FALSE;
    $form['label']['#access'] = FALSE;
    $form['label_display']['#access'] = FALSE;

    // Hide the block title by default.
    $form['label_display']['#value'] = FALSE;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);

    $this->configuration['entity'] = $form_state->getValue('entity');
    $this->configuration['view_mode'] = $form_state->getValue('view_mode');

    if ($entity = $this->entityStorage->load($this->configuration['entity'])) {
      $plugin_definition = $this->getPluginDefinition();
      $admin_label = $plugin_definition['admin_label'];
      $this->configuration['label'] = new FormattableMarkup('@entity_label (@admin_label)', [
        '@entity_label' => $entity->label(),
        '@admin_label' => $admin_label,
      ]);
    }

    $schedule = $form_state->getValue('schedule');

    $this->configuration['seb_type'] = $schedule['seb_type'];

    if (!empty($schedule['time']['start'])) {
      $this->configuration['schedule_time_start'] = $schedule['time']['start']->format('h:i:s A');
    }

    if (!empty($schedule['time']['end'])) {
      $this->configuration['schedule_time_end'] = $schedule['time']['end']->format('h:i:s A');
    }

    if (!empty($schedule['between_dates']['start'])) {
      $this->configuration['between_dates_start'] = $schedule['between_dates']['start']->format('Y-m-d h:i:s A');
    }

    if (!empty($schedule['between_dates']['end'])) {
      $this->configuration['between_dates_end'] = $schedule['between_dates']['end']->format('Y-m-d h:i:s A');
    }

    if ($schedule['seb_type'] == 'custom') {
      foreach ($schedule['dates_fieldset'] as $day_index => $day_item) {
        $day_settings_key = 'dates_fieldset_' . $day_index;
        $this->configuration[$day_settings_key . '_enabled'] = $day_item['enabled'];

        if (!empty($day_item['time']['start'])) {
          $this->configuration[$day_settings_key . '_time_start'] = $day_item['time']['start']->format('h:i:s A');
        }

        if (!empty($day_item['time']['end'])) {
          $this->configuration[$day_settings_key . '_time_end'] = $day_item['time']['end']->format('h:i:s A');
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    if (!$this->isValidNow($this->configuration)) {
      return [];
    }

    if ($entity = $this->getEntity()) {
      $recursive_render_id = $entity->getEntityTypeId() . ':' . $entity->id();
      if (isset(static::$recursiveRenderDepth[$recursive_render_id])) {
        static::$recursiveRenderDepth[$recursive_render_id]++;
      }
      else {
        static::$recursiveRenderDepth[$recursive_render_id] = 1;
      }

      // Protect recursive rendering.
      if (static::$recursiveRenderDepth[$recursive_render_id] > static::RECURSIVE_RENDER_LIMIT) {
        $this->loggerFactory->get('entity')->error('Recursive rendering detected when rendering embedded entity %entity_type: %entity_id. Aborting rendering.', [
          '%entity_type' => $entity->getEntityTypeId(),
          '%entity_id' => $entity->id(),
        ]);
      }

      $render_controller = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
      $view_mode = $this->configuration['view_mode'] ?? 'default';

      return $render_controller->view($entity, $view_mode);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $entity = $this->getEntity();
    if ($entity && $entity->access('view', $account)) {
      return AccessResult::allowed()
        ->cachePerPermissions()
        ->addCacheableDependency($entity);
    }

    return AccessResult::forbidden()
      ->setReason($this->t('User does not have permission to view this entity.'));
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $entity = $this->getEntity();
    $contexts = $entity ? $entity->getCacheContexts() : [];
    return Cache::mergeContexts(parent::getCacheContexts(), $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $entity = $this->getEntity();
    $cache_tags = $entity ? $entity->getCacheTags() : [];
    return Cache::mergeTags(parent::getCacheTags(), $cache_tags);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    $entity = $this->getEntity();
    $max_age = $entity ? $entity->getCacheMaxAge() : Cache::PERMANENT;
    return Cache::mergeMaxAges(parent::getCacheMaxAge(), $max_age);
  }

  /**
   * Gets our entity.
   */
  public function getEntity() {
    if ($entity_id = $this->configuration['entity']) {
      return $this->entityStorage->load($entity_id);
    }

    return NULL;
  }

  /**
   * Get Schedule Field.
   */
  public function getScheduleField($form, FormStateInterface $form_state) {
    $config = $this->configuration;

    $schedule_field['seb_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Schedule type'),
      '#options' => [
        'daily' => $this->t('Daily'),
        'week_days' => $this->t('Week days'),
        'weekend_days' => $this->t('Weekend days'),
        'between_dates' => $this->t('Between dates'),
        'custom' => $this->t('Custom'),
      ],
      "#empty_option" => $this->t('- Select -'),
      '#default_value' => $this->configuration['seb_type'] ?? '',
    ];

    $schedule_field['time'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'container-inline',
        ],
      ],
      '#states' => [
        'invisible' => [
          ':input[name="settings[schedule][seb_type]"]' => [
            ['value' => 'custom'],
            ['value' => 'between_dates'],
          ],
        ],
      ],
    ];

    $schedule_field['time']['start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Starts on'),
      '#title_display' => 'invisible',
      '#date_date_element' => 'none',
    ];

    if (!empty($config['schedule_time_start'])) {
      $schedule_field['time']['start']['#default_value'] = DrupalDateTime::createFromTimestamp(
        strtotime($config['schedule_time_start']));
    }

    $schedule_field['time']['end'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Ends on'),
      '#title_display' => 'invisible',
      '#date_date_element' => 'none',
    ];

    if (!empty($config['schedule_time_end'])) {
      $schedule_field['time']['end']['#default_value'] = DrupalDateTime::createFromTimestamp(
        strtotime($config['schedule_time_end']));
    }

    $schedule_field['between_dates'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'container-inline',
        ],
      ],
      '#states' => [
        'visible' => [
          ':input[name="settings[schedule][seb_type]"]' => [
            ['value' => 'custom'],
            ['value' => 'between_dates'],
          ],
        ],
      ],
    ];

    $schedule_field['between_dates']['start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Starts on'),
      '#title_display' => 'invisible',
    ];

    if (!empty($config['between_dates_start'])) {
      $schedule_field['between_dates']['start']['#default_value'] = DrupalDateTime::createFromTimestamp(
        strtotime($config['between_dates_start']));
    }

    $schedule_field['between_dates']['end'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Ends on'),
      '#title_display' => 'invisible',
    ];

    if (!empty($config['between_dates_end'])) {
      $schedule_field['between_dates']['end']['#default_value'] = DrupalDateTime::createFromTimestamp(
        strtotime($config['between_dates_end']));
    }

    $schedule_field['dates_fieldset'] = [
      '#type' => 'fieldset',
      '#states' => [
        'visible' => [
          ':input[name="settings[schedule][seb_type]"]' => ['value' => 'custom'],
        ],
      ],
    ];

    foreach ($this->days as $day_index => $day) {
      $day_settings_key = 'dates_fieldset_' . $day_index;

      $schedule_field['dates_fieldset'][$day_index]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $day,
        '#default_value' => $config[$day_settings_key . '_enabled'],
      ];

      $schedule_field['dates_fieldset'][$day_index]['time'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'container-inline',
          ],
        ],
      ];

      $schedule_field['dates_fieldset'][$day_index]['time']['start'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Starts on'),
        '#title_display' => 'invisible',
        '#date_date_element' => 'none',
      ];

      if (!empty($config[$day_settings_key . '_time_start'])) {
        $schedule_field['dates_fieldset'][$day_index]['time']['start']['#default_value'] = DrupalDateTime::createFromTimestamp(
          strtotime($config[$day_settings_key . '_time_start']));
      }

      $schedule_field['dates_fieldset'][$day_index]['time']['end'] = [
        '#type' => 'datetime',
        '#title' => $this->t('Ends on'),
        '#title_display' => 'invisible',
        '#date_date_element' => 'none',
      ];

      if (!empty($config[$day_settings_key . '_time_end'])) {
        $schedule_field['dates_fieldset'][$day_index]['time']['end']['#default_value'] = DrupalDateTime::createFromTimestamp(
          strtotime($config[$day_settings_key . '_time_end']));
      }
    }

    return $schedule_field;
  }

  /**
   * Check whether today is weekend or not.
   */
  public function isTodayWeekend() {
    return in_array(date("l"), ["Saturday", "Sunday"]);
  }

  /**
   * Check if the given block config is valid now.
   *
   * @param array $item
   *   Block configuration array.
   */
  public function isValidNow(array $item) {
    if (empty($item['seb_type'])) {
      return FALSE;
    }

    // Check daily, week_days and weekend ads.
    if ($item['seb_type'] === 'daily' || $item['seb_type'] === 'week_days' || $item['seb_type'] === 'weekend_days') {
      if (!empty($item['schedule_time_start']) && !empty($item['schedule_time_end'])) {
        $starts_on = strtotime($item['schedule_time_start']);
        $ends_on = strtotime($item['schedule_time_end']);
        $time_now = strtotime(date('h:i:s A'));
        if ($time_now >= $starts_on && $time_now <= $ends_on) {
          if ($item['seb_type'] === 'daily' ||
              ($item['seb_type'] === 'week_days' && !$this->isTodayWeekend()) ||
              ($item['seb_type'] === 'weekend_days' && $this->isTodayWeekend())
             ) {
            return TRUE;
          }
        }
      }
    }

    // Check between dates and custom days.
    if ($item['seb_type'] === 'between_dates' || $item['seb_type'] === 'custom') {
      if (!empty($item['between_dates_start']) && !empty($item['between_dates_end'])) {
        $btw_starts_on = strtotime($item['between_dates_start']);
        $btw_ends_on = strtotime($item['between_dates_end']);
        $datetime_now = strtotime(date('Y-m-d h:i:s A'));
        if ($datetime_now >= $btw_starts_on && $datetime_now <= $btw_ends_on) {
          if ($item['seb_type'] === 'between_dates') {
            return TRUE;
          }
          elseif ($item['seb_type'] === 'custom') {
            $today = strtolower(date('l'));
            if ($item['dates_fieldset_' . $today . '_enabled'] === 1) {
              if (!empty($item['dates_fieldset_' . $today . '_time_start']) && !empty($item['dates_fieldset_' . $today . '_time_end'])) {
                $time_starts_on = strtotime($item['dates_fieldset_' . $today . '_time_start']);
                $time_ends_on = strtotime($item['dates_fieldset_' . $today . '_time_end']);
                $time_now = strtotime(date('h:i:s A'));
                if ($time_now >= $time_starts_on && $time_now <= $time_ends_on) {
                  return TRUE;
                }
              }

            }
          }
        }
      }
    }

    return FALSE;
  }

}
