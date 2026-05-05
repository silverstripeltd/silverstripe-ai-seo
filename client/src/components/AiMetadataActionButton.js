import React from 'react';
import PropTypes from 'prop-types';
import Button from 'components/Button/Button';

export const AiMetadataActionButton = ({
  fqcn,
  recordId,
  title = 'Metadata',
  tooltip = 'Edit metadata',
}) => (
  <Button
    type="button"
    color="secondary"
    className="ai-metadata__action ai-metadata-toolbar__button"
    icon="tags"
    title={tooltip}
    data-fqcn={fqcn}
    data-record-id={recordId}
  >
    {title}
  </Button>
);

AiMetadataActionButton.propTypes = {
  fqcn: PropTypes.string.isRequired,
  recordId: PropTypes.number.isRequired,
  title: PropTypes.string,
  tooltip: PropTypes.string,
};

export default AiMetadataActionButton;
