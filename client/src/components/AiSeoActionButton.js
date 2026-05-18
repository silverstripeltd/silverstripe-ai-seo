import React from 'react';
import PropTypes from 'prop-types';
import Button from 'components/Button/Button';

/**
 * Renders the CMS toolbar button that opens the SEO modal for a record.
 */
export const AiSeoActionButton = ({
  fqcn,
  recordId,
  title = 'SEO',
  tooltip = 'Edit SEO',
}) => (
  <Button
    type="button"
    color="secondary"
    className="ai-seo__action ai-seo-toolbar__button"
    icon="tags"
    title={tooltip}
    data-fqcn={fqcn}
    data-record-id={recordId}
  >
    {title}
  </Button>
);

AiSeoActionButton.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  title: PropTypes.string,
  tooltip: PropTypes.string,
};

export default AiSeoActionButton;
