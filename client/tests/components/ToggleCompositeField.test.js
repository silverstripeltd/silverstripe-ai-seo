/* eslint-env jest */
import React from 'react';
import { render, screen } from '@testing-library/react';
import ToggleCompositeField from '../../src/components/ToggleCompositeField';

test('clamps heading levels and merges classes', () => {
  render(
    <ToggleCompositeField
      title="Details"
      className="base"
      extraClass="extra"
      data={{ headingLevel: 9 }}
    >
      <span>Child</span>
    </ToggleCompositeField>
  );

  const heading = screen.getByRole('heading', { name: 'Details', level: 6 });
  expect(heading.tagName).toBe('H6');
  expect(heading.closest('div').className).toBe('base extra');
  expect(screen.getByText('Child')).not.toBeNull();
});

test('defaults heading level when value is invalid', () => {
  render(
    <ToggleCompositeField
      title="Info"
      data={{ headingLevel: 'foo' }}
    />
  );

  const heading = screen.getByRole('heading', { name: 'Info', level: 3 });
  expect(heading.tagName).toBe('H3');
});

test('omits heading when title is empty', () => {
  render(<ToggleCompositeField title="" />);
  expect(screen.queryByRole('heading')).toBeNull();
});
