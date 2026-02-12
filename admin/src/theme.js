import { createTheme } from '@mui/material/styles'

const theme = createTheme({
  palette: {
    mode: 'light',
    primary: {
      main: '#146c94',
      dark: '#0c4f6c',
      light: '#4f9cc0',
    },
    secondary: {
      main: '#ff7a18',
      dark: '#c35a0d',
      light: '#ffae73',
    },
    background: {
      default: '#f1f6fb',
      paper: '#ffffff',
    },
  },
  shape: {
    borderRadius: 14,
  },
  typography: {
    fontFamily: '"Space Grotesk", "Segoe UI", sans-serif',
    h4: {
      fontWeight: 700,
      letterSpacing: '-0.02em',
    },
    h5: {
      fontWeight: 700,
      letterSpacing: '-0.01em',
    },
    h6: {
      fontWeight: 700,
    },
    button: {
      textTransform: 'none',
      fontWeight: 600,
    },
  },
  components: {
    MuiPaper: {
      styleOverrides: {
        root: {
          backgroundImage: 'none',
        },
      },
    },
    MuiAppBar: {
      styleOverrides: {
        root: {
          boxShadow: '0 12px 24px rgba(20, 108, 148, 0.16)',
        },
      },
    },
    MuiCard: {
      styleOverrides: {
        root: {
          border: '1px solid #d7e7f4',
          boxShadow: '0 8px 18px rgba(11, 44, 74, 0.08)',
        },
      },
    },
  },
})

export default theme
