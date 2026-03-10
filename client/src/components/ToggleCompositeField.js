import React from 'react';
import PropTypes from 'prop-types';

/**
 * Clamp the heading level to an allowed range.
 *
 * @param {number|string} value
 * @returns {number}
 */
const clampHeadingLevel = (value) => {
  const parsed = parseInt(value, 10);
  if (Number.isNaN(parsed)) {
    return 3;
  }
  return Math.min(6, Math.max(1, parsed));
};

/**
 * Toggleable composite field with a heading and body wrapper.
 *
 * @param {Object} props
 * @param {string|null} props.id
 * @param {string} props.title
 * @param {React.ReactNode|null} props.children
 * @param {string} props.className
 * @param {string} props.extraClass
 * @param {Object|null} props.data
 * @returns {JSX.Element}
 */
const ToggleCompositeField = ({
  id = null,
  title = '',
  children = null,
  className = '',
  extraClass = '',
  data = null,
}) => {
  const headingLevel = clampHeadingLevel(data?.headingLevel);
  const HeadingTag = `h${headingLevel}`;
  const classes = [className, extraClass].filter(Boolean).join(' ');

  return (
    <div id={id} className={classes}>
      {title && (
        <HeadingTag>
          <a href="#">{title}</a>
        </HeadingTag>
      )}
      <div>
        {children}
      </div>
    </div>
  );
};

ToggleCompositeField.propTypes = {
  id: PropTypes.string,
  title: PropTypes.string,
  children: PropTypes.node,
  className: PropTypes.string,
  extraClass: PropTypes.string,
  data: PropTypes.shape({
    headingLevel: PropTypes.oneOfType([PropTypes.number, PropTypes.string]),
  }),
};

export default ToggleCompositeField;
