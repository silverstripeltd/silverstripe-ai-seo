/* eslint-env jest */
/* eslint-disable import/first */
import React from 'react';
import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react';

let mockModalFixture = null;
let mockSubmitHandlers = null;

const mockCreateDefaultFixture = () => ({
  fields: {
    GeneratedAt: '',
    ReviewedAt: '',
    MetaDescription: 'Short summary',
    OGTitle: '',
    OGDescription: '',
    SummaryLong: '',
  },
  metaDescriptionMax: 150,
  timestamp: '2026-02-20 10:00:00',
  reviewConfirmed: false,
  reviewLabel: 'I have reviewed the generated AI metadata',
  showSaveAction: undefined,
});

const resetModalFixture = () => {
  mockModalFixture = mockCreateDefaultFixture();
};

const resetSubmitHandlers = () => {
  mockSubmitHandlers = {
    doRegenerate: jest.fn().mockResolvedValue({ meta: { hadGeneratedMetadata: false } }),
    doSave: jest.fn().mockResolvedValue({ errors: [] }),
  };
};

jest.mock('lib/Config', () => ({
  __esModule: true,
  default: {
    getSection: () => ({
      form: {
        aiMetadataForm: {
          schemaUrl: '/admin/ai-metadata/schema/AiMetadataForm',
        },
      },
    }),
  },
}), { virtual: true });

jest.mock('lib/urls', () => ({
  joinUrlPaths: (...parts) => parts.join('/'),
}), { virtual: true });

jest.mock('components/FormBuilderModal/FormBuilderModal', () => {
  const ReactModule = jest.requireActual('react');

  return ({
    className,
    modalClassName,
    onSubmit,
    title,
  }) => {
    const fixture = mockModalFixture || mockCreateDefaultFixture();
    const fields = fixture.fields || {};
    const wrapperClassName = [className, modalClassName].filter(Boolean).join(' ');
    const showSaveAction = fixture.showSaveAction ?? Boolean(fields.GeneratedAt);
    const regenerateLabel = fields.GeneratedAt ? 'Regenerate' : 'Generate metadata';

    return ReactModule.createElement(
      'div',
      { className: wrapperClassName, role: 'dialog', 'aria-label': title },
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'AiMetadataStatus' },
        ReactModule.createElement('label', { htmlFor: 'generated-at' }, 'Generated at'),
        ReactModule.createElement('input', {
          id: 'generated-at',
          name: 'GeneratedAt',
          defaultValue: fields.GeneratedAt,
        }),
        ReactModule.createElement('label', { htmlFor: 'reviewed-at' }, 'Reviewed at'),
        ReactModule.createElement('input', {
          id: 'reviewed-at',
          name: 'ReviewedAt',
          defaultValue: fields.ReviewedAt,
        }),
      ),
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'KeyTopicsDisplay' },
        ReactModule.createElement('p', null, 'Key topics'),
      ),
      ReactModule.createElement('input', {
        type: 'hidden',
        name: 'KeyTopics',
        defaultValue: 'Topic one',
      }),
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'MetaDescription' },
        ReactModule.createElement('label', { htmlFor: 'meta-description' }, 'Meta description'),
        ReactModule.createElement('input', {
          id: 'meta-description',
          name: 'MetaDescription',
          defaultValue: fields.MetaDescription,
        }),
        ReactModule.createElement('span', {
          'data-ai-metadata-count': 'MetaDescription',
          'data-ai-metadata-max': String(fixture.metaDescriptionMax),
        }),
      ),
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'OGTitle' },
        ReactModule.createElement('label', { htmlFor: 'og-title' }, 'OG title'),
        ReactModule.createElement('input', {
          id: 'og-title',
          name: 'OGTitle',
          defaultValue: fields.OGTitle,
        }),
      ),
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'OGDescription' },
        ReactModule.createElement('label', { htmlFor: 'og-description' }, 'OG description'),
        ReactModule.createElement('input', {
          id: 'og-description',
          name: 'OGDescription',
          defaultValue: fields.OGDescription,
        }),
      ),
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'SummaryLong' },
        ReactModule.createElement('label', { htmlFor: 'summary-long' }, 'Summary long'),
        ReactModule.createElement('textarea', {
          id: 'summary-long',
          name: 'SummaryLong',
          defaultValue: fields.SummaryLong,
        }),
      ),
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'GeneratedTimestamp' },
        ReactModule.createElement('span', {
          'data-ai-metadata-timestamp': fixture.timestamp,
        }, fixture.timestamp),
      ),
      ReactModule.createElement(
        'div',
        { 'data-field-path': 'ReviewConfirmed' },
        ReactModule.createElement(
          'label',
          { htmlFor: 'review-confirmed' },
          ReactModule.createElement('input', {
            id: 'review-confirmed',
            type: 'checkbox',
            name: 'ReviewConfirmed',
            defaultChecked: fixture.reviewConfirmed,
          }),
          fixture.reviewLabel,
        ),
      ),
      ReactModule.createElement(
        'div',
        { className: 'modal-footer' },
        ReactModule.createElement('button', {
          type: 'button',
          name: 'action_doRegenerate',
          onClick: () => onSubmit({}, 'action_doRegenerate', mockSubmitHandlers.doRegenerate),
        }, regenerateLabel),
        showSaveAction ? ReactModule.createElement('button', {
          type: 'button',
          name: 'action_doSave',
          onClick: () => onSubmit({}, 'action_doSave', mockSubmitHandlers.doSave),
        }, 'Apply metadata') : null,
      ),
    );
  };
}, { virtual: true });

jest.mock('state/toasts/ToastsActions', () => ({}), { virtual: true });
jest.mock('redux', () => ({ bindActionCreators: () => ({}) }), { virtual: true });
jest.mock('react-redux', () => ({ connect: () => (Component) => Component }), { virtual: true });

import AiMetadataModalComponent, {
  buildModalSelector,
  buildSchemaUrl,
  formatLengthHint,
  formatTimestamp,
  isReviewRequired,
  syncInlineRegenerateAction,
} from '../../src/components/AiMetadataModal';

const buildActions = () => ({
  toasts: {
    error: jest.fn(),
    info: jest.fn(),
    success: jest.fn(),
  },
});

beforeEach(() => {
  resetModalFixture();
  resetSubmitHandlers();
  window.requestAnimationFrame = (callback) => {
    callback();
    return 1;
  };
});

test('buildSchemaUrl encodes fqcn and appends record id', () => {
  const url = buildSchemaUrl('My\\Class', 42);
  expect(url).toBe('/admin/ai-metadata/schema/AiMetadataForm/42?fqcn=My%5CClass');
});

test('buildModalSelector handles custom and default classes', () => {
  expect(buildModalSelector('ai-metadata-modal extra')).toBe('.ai-metadata-modal.extra');
  expect(buildModalSelector('')).toBe('.ai-metadata-modal');
  expect(buildModalSelector(null)).toBe('.ai-metadata-modal');
});

test('formatTimestamp handles empty and invalid values', () => {
  expect(formatTimestamp('')).toBe('');
  expect(formatTimestamp('not-a-date')).toBe('not-a-date');
});

test('formatTimestamp renders a formatted date when possible', () => {
  const output = formatTimestamp('2026-02-20T10:00:00Z');
  expect(output).toMatch(/2026/);
});

test('formatLengthHint describes max and current counts', () => {
  expect(formatLengthHint(150, 12)).toBe('Recommended max 150 characters. Current: 12');
});

test('isReviewRequired reflects generated and reviewed timestamps', () => {
  const modal = document.createElement('div');
  const generated = document.createElement('input');
  generated.name = 'GeneratedAt';
  generated.value = '2026-02-20 10:00:00';
  modal.appendChild(generated);

  const reviewed = document.createElement('input');
  reviewed.name = 'ReviewedAt';
  modal.appendChild(reviewed);

  expect(isReviewRequired(modal)).toBe(true);

  reviewed.value = '2026-02-20 11:00:00';
  expect(isReviewRequired(modal)).toBe(false);

  reviewed.value = '2026-02-20 09:00:00';
  expect(isReviewRequired(modal)).toBe(true);
});

test('syncInlineRegenerateAction inserts a proxy action above key topics', () => {
  const modal = document.createElement('div');
  const status = document.createElement('div');
  status.setAttribute('data-field-path', 'AiMetadataStatus');
  const keyTopics = document.createElement('div');
  keyTopics.setAttribute('data-field-path', 'KeyTopicsDisplay');
  const footer = document.createElement('div');
  const sourceButton = document.createElement('button');
  sourceButton.type = 'button';
  sourceButton.name = 'action_doRegenerate';
  sourceButton.className = 'btn btn-primary';
  sourceButton.innerHTML = '<span class="btn__title">Generate metadata</span>';
  footer.appendChild(sourceButton);
  modal.appendChild(status);
  modal.appendChild(keyTopics);
  modal.appendChild(footer);

  syncInlineRegenerateAction(modal);

  const inlineAction = modal.querySelector('.ai-metadata-modal__inline-regenerate-action');
  const proxyButton = inlineAction?.querySelector('button');
  expect(inlineAction).not.toBeNull();
  expect(keyTopics.previousElementSibling).toBe(inlineAction);
  expect(proxyButton?.textContent).toContain('Generate metadata');
  expect(proxyButton?.className).toContain('btn btn-primary');
  expect(sourceButton.getAttribute('aria-hidden')).toBe('true');
  expect(sourceButton.className).toContain('ai-metadata-modal__source-action--hidden');
});

test('requires review confirmation before enabling submit for unreviewed metadata', async () => {
  mockModalFixture.fields.GeneratedAt = '2026-02-20 10:00:00';
  mockModalFixture.fields.ReviewedAt = '';

  render(
    <AiMetadataModalComponent
      fqcn="App\\Page"
      recordId={7}
      actions={buildActions()}
    />
  );

  expect(screen.getByRole('dialog', { name: 'Generate metadata using AI' })).not.toBeNull();

  const submitButton = screen.getByRole('button', { name: 'Apply metadata' });
  const reviewCheckbox = screen.getByRole('checkbox', { name: 'I have reviewed the generated AI metadata' });

  await waitFor(() => {
    expect(submitButton.disabled).toBe(true);
  });
  expect(submitButton.getAttribute('title')).toBe('Please confirm you have reviewed the metadata before submitting.');

  fireEvent.click(reviewCheckbox);
  expect(submitButton.disabled).toBe(false);

  fireEvent.click(reviewCheckbox);
  expect(submitButton.disabled).toBe(true);
});

test('enables submit for manual edits after metadata is already reviewed', async () => {
  mockModalFixture.fields.GeneratedAt = '2026-02-20 10:00:00';
  mockModalFixture.fields.ReviewedAt = '2026-02-20 10:00:00';
  mockModalFixture.fields.MetaDescription = 'Current reviewed summary';

  render(
    <AiMetadataModalComponent
      fqcn="App\\Page"
      recordId={7}
      actions={buildActions()}
    />
  );

  const submitButton = screen.getByRole('button', { name: 'Apply metadata' });
  const reviewCheckbox = screen.getByRole('checkbox', { name: 'I have reviewed the generated AI metadata' });
  const metaDescriptionInput = screen.getByLabelText('Meta description');

  await waitFor(() => {
    expect(submitButton.disabled).toBe(true);
  });
  expect(reviewCheckbox.disabled).toBe(true);

  fireEvent.input(metaDescriptionInput, { target: { value: 'Updated reviewed summary' } });
  expect(submitButton.disabled).toBe(false);
});

test('updates the meta description length hint while the editor types', async () => {
  mockModalFixture.fields.MetaDescription = 'Short';

  render(
    <AiMetadataModalComponent
      fqcn="App\\Page"
      recordId={7}
      actions={buildActions()}
    />
  );

  const metaDescriptionInput = screen.getByLabelText('Meta description');

  expect(await screen.findByText('Recommended max 150 characters. Current: 5')).not.toBeNull();

  fireEvent.input(metaDescriptionInput, { target: { value: '1234567890' } });
  expect(screen.getByText('Recommended max 150 characters. Current: 10')).not.toBeNull();

  const longValue = 'x'.repeat(151);
  fireEvent.input(metaDescriptionInput, { target: { value: longValue } });

  const hint = screen.getByText('Recommended max 150 characters. Current: 151');
  expect(hint.className).toContain('text-primary');
});

test('renders the regenerate proxy between status and key topics', async () => {
  render(
    <AiMetadataModalComponent
      fqcn="App\\Page"
      recordId={7}
      actions={buildActions()}
    />
  );

  const keyTopicsField = screen.getByText('Key topics').closest('[data-field-path="KeyTopicsDisplay"]');

  await waitFor(() => {
    const inlineAction = document.querySelector('.ai-metadata-modal__inline-regenerate-action');
    expect(inlineAction).not.toBeNull();
    expect(inlineAction?.nextElementSibling).toBe(keyTopicsField);
  });

  const sourceButton = document.querySelector('.modal-footer button[name="action_doRegenerate"]');
  expect(sourceButton?.getAttribute('aria-hidden')).toBe('true');
});

test('hides the apply action until AI metadata has been generated', () => {
  render(
    <AiMetadataModalComponent
      fqcn="App\\Page"
      recordId={7}
      actions={buildActions()}
    />
  );

  expect(screen.getByRole('button', { name: 'Generate metadata' })).not.toBeNull();
  expect(screen.queryByRole('button', { name: 'Apply metadata' })).toBeNull();
});

test('clicking the regenerate proxy triggers the real footer action', async () => {
  const actions = buildActions();

  render(
    <AiMetadataModalComponent
      fqcn="App\\Page"
      recordId={7}
      actions={actions}
    />
  );

  await waitFor(() => {
    expect(document.querySelector('.ai-metadata-modal__inline-regenerate-action button')).not.toBeNull();
  });

  await act(async () => {
    fireEvent.click(document.querySelector('.ai-metadata-modal__inline-regenerate-action button'));
  });

  expect(mockSubmitHandlers.doRegenerate).toHaveBeenCalledTimes(1);
  await waitFor(() => {
    expect(actions.toasts.info).toHaveBeenCalledWith('Generated AI metadata created. Review and apply to save.');
  });
});

test('shows regenerate and save toasts from FormBuilderModal submit callbacks', async () => {
  mockModalFixture.fields.GeneratedAt = '2026-02-20 10:00:00';
  mockModalFixture.fields.ReviewedAt = '2026-02-20 10:00:00';
  mockModalFixture.fields.MetaDescription = 'Current reviewed summary';
  const actions = buildActions();

  render(
    <AiMetadataModalComponent
      fqcn="App\\Page"
      recordId={7}
      actions={actions}
    />
  );

  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Regenerate' }));
  });

  await waitFor(() => {
    expect(actions.toasts.info).toHaveBeenCalledWith('Generated AI metadata created. Review and apply to save.');
  });

  fireEvent.input(screen.getByLabelText('Meta description'), { target: { value: 'Updated reviewed summary' } });

  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Apply metadata' }));
  });

  await waitFor(() => {
    expect(actions.toasts.success).toHaveBeenCalledWith('Generated AI metadata saved.');
  });
});
