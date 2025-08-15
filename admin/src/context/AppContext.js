import React, { createContext, useContext, useReducer } from 'react';

// Initial state
const initialState = {
  users: [],
  roles: [],
  plugins: [],
  adminMenu: [],
  stats: {},
  restrictions: [],
  loading: false,
  error: null,
  selectedUser: null,
  selectedRole: null,
  isPro: false,
  currentPage: 'dashboard'
};

// Action types
export const actionTypes = {
  SET_LOADING: 'SET_LOADING',
  SET_ERROR: 'SET_ERROR',
  SET_USERS: 'SET_USERS',
  SET_ROLES: 'SET_ROLES',
  SET_PLUGINS: 'SET_PLUGINS',
  SET_ADMIN_MENU: 'SET_ADMIN_MENU',
  SET_STATS: 'SET_STATS',
  SET_RESTRICTIONS: 'SET_RESTRICTIONS',
  ADD_RESTRICTION: 'ADD_RESTRICTION',
  UPDATE_RESTRICTION: 'UPDATE_RESTRICTION',
  DELETE_RESTRICTION: 'DELETE_RESTRICTION',
  SET_SELECTED_USER: 'SET_SELECTED_USER',
  SET_SELECTED_ROLE: 'SET_SELECTED_ROLE',
  SET_CURRENT_PAGE: 'SET_CURRENT_PAGE',
  CLEAR_ERROR: 'CLEAR_ERROR'
};

// Reducer function
const appReducer = (state, action) => {
  switch (action.type) {
    case actionTypes.SET_LOADING:
      return {
        ...state,
        loading: action.payload
      };

    case actionTypes.SET_ERROR:
      return {
        ...state,
        error: action.payload,
        loading: false
      };

    case actionTypes.CLEAR_ERROR:
      return {
        ...state,
        error: null
      };

    case actionTypes.SET_USERS:
      return {
        ...state,
        users: action.payload
      };

    case actionTypes.SET_ROLES:
      return {
        ...state,
        roles: action.payload
      };

    case actionTypes.SET_PLUGINS:
      return {
        ...state,
        plugins: action.payload
      };

    case actionTypes.SET_ADMIN_MENU:
      return {
        ...state,
        adminMenu: action.payload
      };

    case actionTypes.SET_STATS:
      return {
        ...state,
        stats: action.payload
      };

    case actionTypes.SET_RESTRICTIONS:
      return {
        ...state,
        restrictions: action.payload
      };

    case actionTypes.ADD_RESTRICTION:
      return {
        ...state,
        restrictions: [...state.restrictions, action.payload]
      };

    case actionTypes.UPDATE_RESTRICTION:
      return {
        ...state,
        restrictions: state.restrictions.map(restriction =>
          restriction.id === action.payload.id ? action.payload : restriction
        )
      };

    case actionTypes.DELETE_RESTRICTION:
      return {
        ...state,
        restrictions: state.restrictions.filter(restriction =>
          restriction.id !== action.payload
        )
      };

    case actionTypes.SET_SELECTED_USER:
      return {
        ...state,
        selectedUser: action.payload
      };

    case actionTypes.SET_SELECTED_ROLE:
      return {
        ...state,
        selectedRole: action.payload
      };

    case actionTypes.SET_CURRENT_PAGE:
      return {
        ...state,
        currentPage: action.payload
      };

    default:
      return state;
  }
};

// Create context
const AppContext = createContext();

// Context provider component
export const AppProvider = ({ children, value = {} }) => {
  const [state, dispatch] = useReducer(appReducer, {
    ...initialState,
    ...value
  });

  // Action creators
  const actions = {
    setLoading: (loading) => dispatch({ type: actionTypes.SET_LOADING, payload: loading }),

    setError: (error) => dispatch({ type: actionTypes.SET_ERROR, payload: error }),

    clearError: () => dispatch({ type: actionTypes.CLEAR_ERROR }),

    setUsers: (users) => dispatch({ type: actionTypes.SET_USERS, payload: users }),

    setRoles: (roles) => dispatch({ type: actionTypes.SET_ROLES, payload: roles }),

    setPlugins: (plugins) => dispatch({ type: actionTypes.SET_PLUGINS, payload: plugins }),

    setAdminMenu: (adminMenu) => dispatch({ type: actionTypes.SET_ADMIN_MENU, payload: adminMenu }),

    setStats: (stats) => dispatch({ type: actionTypes.SET_STATS, payload: stats }),

    setRestrictions: (restrictions) => dispatch({ type: actionTypes.SET_RESTRICTIONS, payload: restrictions }),

    addRestriction: (restriction) => dispatch({ type: actionTypes.ADD_RESTRICTION, payload: restriction }),

    updateRestriction: (restriction) => dispatch({ type: actionTypes.UPDATE_RESTRICTION, payload: restriction }),

    deleteRestriction: (restrictionId) => dispatch({ type: actionTypes.DELETE_RESTRICTION, payload: restrictionId }),

    setSelectedUser: (user) => dispatch({ type: actionTypes.SET_SELECTED_USER, payload: user }),

    setSelectedRole: (role) => dispatch({ type: actionTypes.SET_SELECTED_ROLE, payload: role }),

    setCurrentPage: (page) => dispatch({ type: actionTypes.SET_CURRENT_PAGE, payload: page })
  };

  const contextValue = {
    ...state,
    ...actions
  };

  return (
    <AppContext.Provider value={contextValue}>
      {children}
    </AppContext.Provider>
  );
};

// Custom hook to use the context
export const useAppContext = () => {
  const context = useContext(AppContext);
  if (!context) {
    throw new Error('useAppContext must be used within an AppProvider');
  }
  return context;
};

export default AppContext;
