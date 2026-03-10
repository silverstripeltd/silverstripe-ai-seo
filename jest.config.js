module.exports = {
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/client/src/**/__tests__/**/*.test.js'],
  transform: {
    '^.+\\.[jt]sx?$': 'babel-jest',
  },
};
