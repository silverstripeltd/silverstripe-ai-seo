/* eslint-env jest */
import {
  buildModalSelector,
  buildSchemaUrl,
  formatLengthHint,
  formatTimestamp,
  isReviewRequired,
} from '../../src/components/AiMetadataModal';

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

jest.mock('components/FormBuilderModal/FormBuilderModal', () => () => null, { virtual: true });
jest.mock('state/toasts/ToastsActions', () => ({}), { virtual: true });
jest.mock('redux', () => ({ bindActionCreators: () => ({}) }), { virtual: true });
jest.mock('react-redux', () => ({ connect: () => (Component) => Component }), { virtual: true });

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
