/* global window */
import React from 'react';
import { createRoot } from 'react-dom/client';
import { loadComponent } from 'lib/Injector';

const jQuery = window.jQuery || window.$;

jQuery.entwine('ss', ($) => {
  $('.cms-edit-form .ai-metadata__action').entwine({
    ReactRoot: null,
    ReactContainer: null,
    Component: null,

    onclick(e) {
      e.preventDefault();
      const fqcn = this.attr('data-fqcn');
      const recordId = parseInt(this.attr('data-record-id'), 10);
      if (!fqcn || !recordId) {
        jQuery.noticeAdd({
          text: 'Save the page before opening AI Metadata.',
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
