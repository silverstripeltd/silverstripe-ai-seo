/* global window */
import React from 'react';
import { createRoot } from 'react-dom/client';
import { loadComponent } from 'lib/Injector';

const jQuery = window.jQuery || window.$;
const AI_METADATA_RECORD_CLASS_FIELD = 'AiMetadataRecordClass';

const getCmsContent = ($element) => $element.closest('.cms-content');

const getEditForm = ($element) => getCmsContent($element).find('.cms-edit-form').first();

const getAiMetadataRecordId = ($element) => parseInt(
  getEditForm($element).find('input[name=ID]').val(),
  10,
);

const getAiMetadataRecordClass = ($element) => {
  const value = getEditForm($element).find(`input[name=${AI_METADATA_RECORD_CLASS_FIELD}]`).val();
  return typeof value === 'string' ? value.trim() : '';
};

const getAiMetadataRecordContext = ($element) => {
  const fqcn = getAiMetadataRecordClass($element);
  const recordId = getAiMetadataRecordId($element);

  if (!fqcn || !recordId) {
    return null;
  }

  return {
    fqcn,
    recordId,
  };
};

const getAiMetadataInjectorContext = ($element) => {
  const cmsContent = getCmsContent($element).attr('id');
  return cmsContent ? { context: cmsContent } : {};
};

jQuery.entwine('ss.ai-metadata', ($) => {
  $('.js-injector-boot .preview-mode-selector').entwine({
    ReactRoot: null,
    ReactContainer: null,
    Component: null,

    clearToolbarButton() {
      const root = this.getReactRoot();
      if (root) {
        root.unmount();
        this.setReactRoot(null);
      }

      const container = this.getReactContainer();
      if (container) {
        container.remove();
        this.setReactContainer(null);
      }
    },

    getOrCreateToolbarButtonContainer() {
      let container = this.getReactContainer();
      if (container) {
        return container;
      }

      container = $('<span class="ai-metadata__placeholder"></span>');
      const sharePlaceholder = this.find('> .share-draft-content__placeholder').first();
      if (sharePlaceholder.length) {
        sharePlaceholder.before(container);
      } else {
        const firstChild = this.children().first();
        if (firstChild.length) {
          firstChild.before(container);
        } else {
          this.prepend(container);
        }
      }

      this.setReactContainer(container);

      return container;
    },

    onmatch() {
      const recordContext = getAiMetadataRecordContext(this);
      if (!recordContext) {
        this.clearToolbarButton();
        this._super();
        return;
      }

      let Component = this.getComponent();
      if (!Component) {
        Component = loadComponent('AiMetadataActionButton', getAiMetadataInjectorContext(this));
        this.setComponent(Component);
      }

      const container = this.getOrCreateToolbarButtonContainer();
      let root = this.getReactRoot();
      if (!root) {
        root = createRoot(container[0]);
        this.setReactRoot(root);
      }

      root.render(
        <Component
          fqcn={recordContext.fqcn}
          recordId={recordContext.recordId}
        />
      );

      this._super();
    },

    onunmatch() {
      this.clearToolbarButton();
      this._super();
    },
  });
});

jQuery.entwine('ss', ($) => {
  $('.ai-metadata__action').entwine({
    ReactRoot: null,
    ReactContainer: null,
    Component: null,

    onclick(e) {
      e.preventDefault();
      const fqcn = this.attr('data-fqcn');
      const recordId = parseInt(this.attr('data-record-id'), 10);
      if (!fqcn || !recordId) {
        jQuery.noticeAdd({
          text: 'Save the page before opening Metadata.',
          type: 'warning',
        });
        return false;
      }

      let container = this.getReactContainer();
      if (!container) {
        container = $('<div class="ai-metadata-modal__container"></div>');
        $('body').append(container);
        this.setReactContainer(container);
      }

      let root = this.getReactRoot();
      if (!root) {
        root = createRoot(container[0]);
        this.setReactRoot(root);
      }

      let Component = this.getComponent();
      if (!Component) {
        Component = loadComponent('AiMetadataModal');
        this.setComponent(Component);
      }

      const self = this;
      const handleClosed = () => {
        const activeRoot = self.getReactRoot();
        if (activeRoot) {
          activeRoot.unmount();
          self.setReactRoot(null);
        }
        const activeContainer = self.getReactContainer();
        if (activeContainer) {
          activeContainer.remove();
          self.setReactContainer(null);
        }
        self.blur();
      };

      root.render(
        <Component
          fqcn={fqcn}
          recordId={recordId}
          onClosed={handleClosed}
        />
      );

      return false;
    },

    onunmatch() {
      const root = this.getReactRoot();
      if (root) {
        root.unmount();
        this.setReactRoot(null);
      }
      const container = this.getReactContainer();
      if (container) {
        container.remove();
        this.setReactContainer(null);
      }
    },
  });
});
