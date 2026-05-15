import React, { useCallback, useEffect, useRef, useState } from 'react';
import PropTypes from 'prop-types';
import { bindActionCreators } from 'redux';
import { connect } from 'react-redux';
import Config from 'lib/Config';
import { joinUrlPaths } from 'lib/urls';
import FormBuilderModal from 'components/FormBuilderModal/FormBuilderModal';
import * as toastsActions from 'state/toasts/ToastsActions';

/**
 * Build the schema URL for the selected record.
 *
 * @param {string} fqcn
 * @param {number} recordId
 * @returns {string}
 */
export const buildSchemaUrl = (fqcn, recordId) => {
  const { schemaUrl } = Config.getSection('SilverstripeLtd\\AiMetadata\\Controllers\\AiMetadataController').form.aiMetadataForm;
  const base = joinUrlPaths(schemaUrl, recordId.toString());
  return `${base}?fqcn=${encodeURIComponent(fqcn)}`;
};

/**
 * Read modal configuration provided by the backend.
 *
 * @returns {Object}
 */
const getModalConfig = () => {
  const controllerConfig = Config.getSection('SilverstripeLtd\\AiMetadata\\Controllers\\AiMetadataController') || {};
  return controllerConfig.form?.aiMetadataForm || {};
};

/**
 * Convert a class name list into a selector for query-based lookups.
 *
 * @param {string|null|undefined} className
 * @returns {string}
 */
export const buildModalSelector = (className) => {
  const rawClassName = typeof className === 'string' && className.trim()
    ? className
    : 'ai-metadata-modal';
  const classes = rawClassName.split(/\s+/).filter(Boolean);
  return `.${classes.join('.')}`;
};

/**
 * Read a hidden field value from the modal form.
 *
 * @param {HTMLElement|null} modal
 * @param {string} fieldName
 * @returns {string}
 */
export const readFieldValue = (modal, fieldName) => {
  if (!modal) {
    return '';
  }
  const input = modal.querySelector(`[name="${fieldName}"]`);
  if (!input) {
    return '';
  }
  return String(input.value || '').trim();
};

/**
 * Format a timestamp string using the browser locale/timezone.
 *
 * @param {string} raw
 * @returns {string}
 */
export const formatTimestamp = (raw) => {
  const trimmed = typeof raw === 'string' ? raw.trim() : '';
  if (!trimmed) {
    return '';
  }
  const normalised = trimmed.includes('T') ? trimmed : trimmed.replace(' ', 'T');
  const hasZone = /[zZ]|[+-]\d{2}:?\d{2}$/.test(normalised);
  const date = new Date(hasZone ? normalised : `${normalised}Z`);
  if (Number.isNaN(date.getTime())) {
    return trimmed;
  }
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
};

/**
 * Format the meta description length hint.
 *
 * @param {number} max
 * @param {number} current
 * @returns {string}
 */
export const formatLengthHint = (max, current) => `Recommended max ${max} characters. Current: ${current}`;

const editableFieldNames = [
  'MetaDescription',
  'OGTitle',
  'OGDescription',
  'SummaryLong',
];

const regenerateActionSelector = 'button[name="action_doRegenerate"]';
const inlineRegenerateActionClassName = 'ai-metadata-modal__inline-regenerate-action';
const inlineRegenerateButtonClassName = 'ai-metadata-modal__inline-regenerate-button';
const hiddenSourceActionClassName = 'ai-metadata-modal__source-action--hidden';
const modalTitle = 'Generate metadata using AI';
const confirmTooltipText = 'Please confirm you have reviewed the metadata before submitting.';
const regenerateNoticeText = 'Generated AI metadata refreshed. Review and apply to save.';
const generateNoticeText = 'Generated AI metadata created. Review and apply to save.';
const savedNoticeText = 'Generated AI metadata saved.';
const regenerateErrorText = 'Failed to refresh generated AI metadata.';
const saveErrorText = 'Failed to save generated AI metadata.';

/**
 * Snapshot editable field values.
 *
 * @param {HTMLElement|null} modal
 * @returns {Object}
 */
const readEditableValues = (modal) => editableFieldNames.reduce((accumulator, fieldName) => {
  accumulator[fieldName] = readFieldValue(modal, fieldName);
  return accumulator;
}, {});

/**
 * Determine whether metadata still needs review.
 *
 * @param {HTMLElement|null} modal
 * @returns {boolean}
 */
export const isReviewRequired = (modal) => {
  const generatedAt = readFieldValue(modal, 'GeneratedAt');
  if (!generatedAt) {
    return false;
  }
  const reviewedAt = readFieldValue(modal, 'ReviewedAt');
  if (!reviewedAt) {
    return true;
  }
  return reviewedAt < generatedAt;
};

/**
 * Find the rendered field wrapper for a schema field name.
 *
 * @param {HTMLElement|null} modal
 * @param {string} fieldName
 * @returns {HTMLElement|null}
 */
const findFieldElement = (modal, fieldName) => {
  if (!modal) {
    return null;
  }
  const selectors = [
    `[data-field-path="${fieldName}"]`,
    `[data-field-name="${fieldName}"]`,
    `#${fieldName}`,
    `[name="${fieldName}"]`,
  ];
  const containerSelectors = [
    '[data-field-path]',
    '[data-field-name]',
    '.field',
    '.form-group',
    '.form__field',
  ];
  const element = selectors
    .map((selector) => modal.querySelector(selector))
    .find(Boolean);
  if (!element) {
    return null;
  }
  return element.closest(containerSelectors.join(', ')) || element;
};

/**
 * Remove the inline regenerate proxy and restore the original footer action.
 *
 * @param {HTMLElement|null} modal
 * @returns {void}
 */
const removeInlineRegenerateAction = (modal) => {
  if (!modal) {
    return;
  }
  modal.querySelector(`.${inlineRegenerateActionClassName}`)?.remove();
  const sourceButton = modal.querySelector(regenerateActionSelector);
  if (!sourceButton) {
    return;
  }
  sourceButton.classList.remove(hiddenSourceActionClassName);
  sourceButton.removeAttribute('aria-hidden');
};

/**
 * Mirror the real regenerate action with a proxy button in the form body.
 *
 * @param {HTMLElement|null} modal
 * @returns {void}
 */
export const syncInlineRegenerateAction = (modal) => {
  if (!modal) {
    return;
  }
  const sourceButton = modal.querySelector(regenerateActionSelector);
  const keyTopicsHeaderField = findFieldElement(modal, 'AiMetadataKeyTopicsHeader');
  const keyTopicsField = findFieldElement(modal, 'KeyTopicsDisplay');
  const statusField = findFieldElement(modal, 'AiMetadataStatus');
  const anchorField = keyTopicsHeaderField || keyTopicsField;
  if (!sourceButton || (!anchorField && !statusField)) {
    removeInlineRegenerateAction(modal);
    return;
  }

  let inlineAction = modal.querySelector(`.${inlineRegenerateActionClassName}`);
  if (!inlineAction) {
    inlineAction = document.createElement('div');
    inlineAction.className = inlineRegenerateActionClassName;
  }

  let proxyButton = inlineAction.querySelector('button');
  if (!proxyButton) {
    proxyButton = document.createElement('button');
    proxyButton.type = 'button';
    proxyButton.className = 'btn';
    proxyButton.addEventListener('click', (event) => {
      event.preventDefault();
      const currentSourceButton = modal.querySelector(regenerateActionSelector);
      if (!currentSourceButton || currentSourceButton.disabled) {
        return;
      }
      // Keep the real FormAction in the footer so FormBuilderModal retains its submit wiring.
      // This inline button is only a proxy trigger because moving or cloning the source action breaks it.
      currentSourceButton.click();
      const schedule = window.requestAnimationFrame
        ? window.requestAnimationFrame.bind(window)
        : (callback) => window.setTimeout(callback, 16);
      schedule(() => syncInlineRegenerateAction(modal));
    });
    inlineAction.appendChild(proxyButton);
  }

  const sourceClasses = [...sourceButton.classList]
    .filter((className) => className !== hiddenSourceActionClassName)
    .join(' ');
  if (proxyButton.className !== sourceClasses) {
    proxyButton.className = sourceClasses;
  }
  proxyButton.classList.add(inlineRegenerateButtonClassName);
  if (proxyButton.innerHTML !== sourceButton.innerHTML) {
    proxyButton.innerHTML = sourceButton.innerHTML;
  }
  proxyButton.disabled = sourceButton.disabled;

  const sourceTitle = sourceButton.getAttribute('title');
  if (sourceTitle) {
    proxyButton.setAttribute('title', sourceTitle);
  } else {
    proxyButton.removeAttribute('title');
  }

  const sourceAriaDisabled = sourceButton.getAttribute('aria-disabled');
  if (sourceAriaDisabled) {
    proxyButton.setAttribute('aria-disabled', sourceAriaDisabled);
  } else {
    proxyButton.removeAttribute('aria-disabled');
  }

  // Hide the original footer action once the proxy is ready so editors only see one regenerate button.
  sourceButton.classList.add(hiddenSourceActionClassName);
  sourceButton.setAttribute('aria-hidden', 'true');

  if (anchorField?.parentNode) {
    if (anchorField.previousElementSibling !== inlineAction) {
      anchorField.parentNode.insertBefore(inlineAction, anchorField);
    }
    return;
  }
  if (statusField?.nextElementSibling !== inlineAction) {
    statusField?.insertAdjacentElement('afterend', inlineAction);
  }
};

/**
 * AI metadata modal wrapper around FormBuilderModal.
 *
 * @param {Object} props
 * @param {string} props.fqcn
 * @param {number} props.recordId
 * @param {Function} [props.onClosed]
 * @param {Object} props.actions
 * @returns {JSX.Element}
 */
const AiMetadataModal = ({
  fqcn,
  recordId,
  onClosed,
  actions,
}) => {
  const modalConfig = getModalConfig();
  const modalClassName = modalConfig.modalClassName;
  const className = modalConfig.className || 'ai-metadata-modal';
  const modalSelector = modalConfig.modalSelector || buildModalSelector(className);
  const updatePendingRef = useRef(false);
  const baselineValuesRef = useRef(null);
  const reviewStateRef = useRef({ generatedAt: '', reviewedAt: '' });
  const [isOpen, setIsOpen] = useState(true);

  /**
   * DOM helper for locating the current modal instance.
   *
   * @returns {HTMLElement|null}
   */
  const getModalElement = useCallback(() => document.querySelector(modalSelector), [modalSelector]);

  /**
   * Enable/disable submit controls based on whether review is required and confirmed.
   *
   * @returns {void}
   */
  const toggleSubmitState = useCallback(() => {
    const modal = getModalElement();
    if (!modal) {
      return;
    }
    const generatedAt = readFieldValue(modal, 'GeneratedAt');
    const reviewedAt = readFieldValue(modal, 'ReviewedAt');
    if (
      !baselineValuesRef.current
      || generatedAt !== reviewStateRef.current.generatedAt
      || reviewedAt !== reviewStateRef.current.reviewedAt
    ) {
      baselineValuesRef.current = readEditableValues(modal);
      reviewStateRef.current = { generatedAt, reviewedAt };
    }
    const baselineValues = baselineValuesRef.current || {};
    const hasChanges = editableFieldNames.some(
      (fieldName) => readFieldValue(modal, fieldName) !== (baselineValues[fieldName] || '')
    );
    const reviewCheckbox = modal.querySelector('input[name="ReviewConfirmed"]');
    const submitButton = modal.querySelector('button[name="action_doSave"]');
    const reviewRequired = isReviewRequired(modal);
    if (reviewCheckbox) {
      reviewCheckbox.disabled = !reviewRequired;
    }
    if (!submitButton) {
      return;
    }
    const isConfirmed = reviewCheckbox?.checked ?? false;
    const disabled = reviewRequired ? !isConfirmed : !hasChanges;
    submitButton.disabled = disabled;
    submitButton.classList.toggle('disabled', disabled);
    const tooltipText = reviewRequired && disabled ? confirmTooltipText : '';
    if (tooltipText) {
      submitButton.setAttribute('title', tooltipText);
    } else {
      submitButton.removeAttribute('title');
    }
    submitButton.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    const tooltipTarget = submitButton.parentElement;
    if (tooltipTarget) {
      if (tooltipText) {
        tooltipTarget.setAttribute('title', tooltipText);
      } else {
        tooltipTarget.removeAttribute('title');
      }
    }
  }, [confirmTooltipText, getModalElement]);

  /**
   * Update length counters/hints next to fields that have recommended maximums.
   *
   * @returns {void}
   */
  const updateLengthIndicators = useCallback(() => {
    const modal = getModalElement();
    if (!modal) {
      return;
    }
    // Field hints are rendered server-side with data attributes, so we update them in-place.
    const hints = modal.querySelectorAll('[data-ai-metadata-count]');
    hints.forEach((element) => {
      const fieldName = element.getAttribute('data-ai-metadata-count');
      const maxValue = parseInt(element.getAttribute('data-ai-metadata-max') || '0', 10);
      const input = modal.querySelector(`[name="${fieldName}"]`);
      if (!input) {
        return;
      }
      const length = input.value.length;
      const hintText = formatLengthHint(maxValue, length);
      if (element.textContent !== hintText) {
        element.textContent = hintText;
      }
      element.classList.toggle('text-primary', length > maxValue);
    });
  }, [getModalElement]);

  /**
   * Update generated timestamps to local timezone formatting.
   *
   * @returns {void}
   */
  const updateTimestampIndicators = useCallback(() => {
    const modal = getModalElement();
    if (!modal) {
      return;
    }
    const timestamps = modal.querySelectorAll('[data-ai-metadata-timestamp]');
    timestamps.forEach((element) => {
      const raw = element.getAttribute('data-ai-metadata-timestamp') || '';
      const formatted = formatTimestamp(raw);
      if (formatted && element.textContent !== formatted) {
        element.textContent = formatted;
      }
    });
  }, [getModalElement]);

  /**
   * Centralised batch of DOM updates we may trigger from multiple event sources.
   *
   * @returns {void}
   */
  const runModalUpdates = useCallback(() => {
    syncInlineRegenerateAction(getModalElement());
    toggleSubmitState();
    updateLengthIndicators();
    updateTimestampIndicators();
  }, [getModalElement, toggleSubmitState, updateLengthIndicators, updateTimestampIndicators]);

  /**
   * Schedule modal DOM updates to avoid mutation observer feedback loops.
   *
   * @returns {void}
   */
  const scheduleModalUpdates = useCallback(() => {
    if (updatePendingRef.current) {
      return;
    }
    updatePendingRef.current = true;
    // Batch DOM reads/writes to avoid mutation observer feedback loops.
    const schedule = window.requestAnimationFrame
      ? window.requestAnimationFrame.bind(window)
      : (callback) => window.setTimeout(callback, 16);
    schedule(() => {
      updatePendingRef.current = false;
      runModalUpdates();
    });
  }, [runModalUpdates]);

  /**
   * Wire up input listeners + mutation observers so footer controls remain in sync with schema-driven DOM.
   *
   * @returns {void}
   */
  useEffect(() => {
    /**
      * Handle input/change events from modal fields.
      *
      * @param {Event} event
      */
    const handleInput = (event) => {
      if (!event.target || !event.target.name) {
        return;
      }
      if (event.target.name === 'ReviewConfirmed') {
        toggleSubmitState();
      }
      if (editableFieldNames.includes(event.target.name)) {
        toggleSubmitState();
      }
      if (event.target.name === 'MetaDescription') {
        updateLengthIndicators();
      }
    };
    document.addEventListener('input', handleInput);
    document.addEventListener('change', handleInput);

    let modalObserver = null;
    let bodyObserver = null;
    /**
      * Determine whether the mutation list contains element changes.
      *
      * @param {MutationRecord[]} mutationList
      */
    const hasElementMutations = (mutationList) => mutationList.some((record) => {
      const nodes = [...record.addedNodes, ...record.removedNodes];
      return nodes.some((node) => node.nodeType === 1);
    });
    /**
      * Observe the modal for schema-driven DOM changes.
      */
    const observeModal = () => {
      const modal = getModalElement();
      if (!modal) {
        return false;
      }
      if (modalObserver) {
        modalObserver.disconnect();
      }
      // Observe schema-driven updates so we can keep footer controls aligned.
      modalObserver = new MutationObserver((mutationList) => {
        if (!hasElementMutations(mutationList)) {
          return;
        }
        scheduleModalUpdates();
      });
      modalObserver.observe(modal, { childList: true, subtree: true });
      return true;
    };
    if (!observeModal()) {
      // Wait for the modal to mount before applying DOM updates.
      bodyObserver = new MutationObserver(() => {
        if (observeModal()) {
          bodyObserver.disconnect();
          bodyObserver = null;
          scheduleModalUpdates();
        }
      });
      bodyObserver.observe(document.body, { childList: true, subtree: true });
    }

    scheduleModalUpdates();
    return () => {
      document.removeEventListener('input', handleInput);
      document.removeEventListener('change', handleInput);
      if (modalObserver) {
        modalObserver.disconnect();
      }
      if (bodyObserver) {
        bodyObserver.disconnect();
      }
    };
  }, [
    getModalElement,
    scheduleModalUpdates,
    toggleSubmitState,
    updateLengthIndicators,
    updateTimestampIndicators,
  ]);

  const schemaUrl = buildSchemaUrl(fqcn, recordId);

  /**
   * Close handler - allows CMS to clear modal state.
   *
   * @returns {void}
   */
  const handleClosed = useCallback(() => {
    setIsOpen(false);
    if (typeof onClosed === 'function') {
      onClosed();
    }
  }, [onClosed, setIsOpen]);

  /**
   * Submit handler for both actions: regenerate (AI call) and save (persist to DB).
   *
   * @param {Object} data
   * @param {string} action
   * @param {Function} submitFn
   * @returns {Promise<void>}
   */
  const handleSubmit = useCallback(async (data, action, submitFn) => {
    let formSchema = null;
    const isRegenerate = action && action.indexOf('doRegenerate') !== -1;
    const isSave = action && action.indexOf('doSave') !== -1;
    try {
      formSchema = await submitFn();
    } catch (error) {
      const message = error?.message || (isRegenerate ? regenerateErrorText : saveErrorText);
      actions.toasts.error(message);
      return Promise.resolve();
    }

    const hasErrors = Array.isArray(formSchema?.errors) && formSchema.errors.length > 0;
    if (!hasErrors && isRegenerate) {
      const hadGeneratedMetadata = formSchema?.meta?.hadGeneratedMetadata;
      const noticeText = hadGeneratedMetadata ? regenerateNoticeText : generateNoticeText;
      actions.toasts.info(noticeText);
    }
    if (!hasErrors && isSave) {
      actions.toasts.success(savedNoticeText);
    }

    scheduleModalUpdates();
    return Promise.resolve();
  }, [
    actions,
    scheduleModalUpdates,
    regenerateNoticeText,
    generateNoticeText,
    savedNoticeText,
    regenerateErrorText,
    saveErrorText,
  ]);

  return (
    <FormBuilderModal
      title={modalTitle}
      isOpen={isOpen}
      schemaUrl={schemaUrl}
      identifier="AiMetadataModal"
      onSubmit={handleSubmit}
      onClosed={handleClosed}
      autoFocus
      size="xl"
      className={className}
      modalClassName={modalClassName}
    />
  );
};

AiMetadataModal.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  onClosed: PropTypes.func,
  actions: PropTypes.shape({
    toasts: PropTypes.shape({
      error: PropTypes.func.isRequired,
      info: PropTypes.func.isRequired,
      success: PropTypes.func.isRequired,
    }).isRequired,
  }).isRequired,
};

const mapDispatchToProps = (dispatch) => ({
  actions: {
    toasts: bindActionCreators(toastsActions, dispatch),
  },
});

export default connect(null, mapDispatchToProps)(AiMetadataModal);
