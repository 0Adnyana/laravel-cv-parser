# demo-zustand-store Specification

## Purpose
Zustand store for the CV Parser demo page — typed state, async actions, and derived selectors for the upload/parse flow.

## Requirements

### Requirement: Demo Zustand store module

The frontend MUST provide a Zustand store dedicated to the CV Parser demo page at `resources/js/stores/demo-store.ts` (or equivalent path under `resources/js/stores/`).

The store MUST expose typed state for:

| Field | Type | Purpose |
|---|---|---|
| `selectedFile` | `File \| null` | Currently selected PDF |
| `statusAvailable` | `boolean` | Whether parser is configured and available |
| `statusWarning` | `string \| null` | Warning from status endpoint when unavailable |
| `statusLoading` | `boolean` | Whether initial status fetch is in progress |
| `uiState` | `'idle' \| 'processing' \| 'success' \| 'error'` | Parse flow state machine |
| `errorMessage` | `string \| null` | User-visible parse error |
| `result` | `ParseCvData \| null` | Successful parse payload |

The store MUST import types from `@/types/cv-parser` and MUST NOT duplicate API response shapes.

#### Scenario: Store exports typed hooks

- **WHEN** the demo page imports the demo store
- **THEN** TypeScript enforces the state shape and action signatures
- **THEN** `ParseCvData` is used for the `result` field

### Requirement: Status bootstrap action

The store MUST expose an action (e.g. `fetchStatus`) that calls `fetchParserStatus` from `@/lib/parse-cv`.

On success, the action MUST set `statusAvailable` and `statusWarning` from the response.

On failure, the action MUST set `statusAvailable` to `false` and `statusWarning` to a fallback message (e.g. "Unable to check parser status.").

The action MUST set `statusLoading` to `false` when complete (success or failure).

#### Scenario: Successful status check

- **WHEN** `fetchStatus` is invoked and the API returns `{ available: true, warning: null }`
- **THEN** `statusAvailable` is `true`
- **THEN** `statusWarning` is `null`
- **THEN** `statusLoading` is `false`

#### Scenario: Failed status check

- **WHEN** `fetchStatus` is invoked and the network request fails
- **THEN** `statusAvailable` is `false`
- **THEN** `statusWarning` contains a user-readable error message
- **THEN** `statusLoading` is `false`

### Requirement: File selection action

The store MUST expose an action (e.g. `selectFile`) that accepts `File | null`.

When a new file is selected, the action MUST reset `errorMessage`, `result`, and `uiState` to `'idle'`.

#### Scenario: New file clears prior result

- **WHEN** the user selects a PDF after a successful parse
- **THEN** `result` is cleared
- **THEN** `uiState` is `'idle'`
- **THEN** `errorMessage` is `null`

### Requirement: Parse submission action

The store MUST expose an async action (e.g. `submitParse`) that:

1. Returns early without side effects when no file is selected, status is unavailable, or `uiState` is `'processing'`
2. Sets `uiState` to `'processing'`, clears `errorMessage` and `result`
3. Calls `parseCv(selectedFile)` from `@/lib/parse-cv`
4. On success: sets `result`, clears `selectedFile`, sets `uiState` to `'success'`
5. On failure: sets `uiState` to `'error'` and `errorMessage` from the thrown error (or a generic fallback)

The store action MUST NOT reset the DOM file input directly; the page component MAY clear the input ref after successful parse.

#### Scenario: Successful parse updates store

- **WHEN** `submitParse` completes with HTTP 200
- **THEN** `result` contains the parsed `data` payload
- **THEN** `selectedFile` is `null`
- **THEN** `uiState` is `'success'`

#### Scenario: Parse error surfaces message

- **WHEN** `parseCv` throws an `Error` with message "The cv field must be a file of type: pdf."
- **THEN** `uiState` is `'error'`
- **THEN** `errorMessage` equals that message

#### Scenario: Guard against duplicate submit

- **WHEN** `submitParse` is called while `uiState` is `'processing'`
- **THEN** no additional parse request is initiated

### Requirement: Derived submit disabled state

The store MUST expose a selector or getter (e.g. `isSubmitDisabled`) that returns `true` when any of:

- `statusLoading` is `true`
- `statusAvailable` is `false`
- `selectedFile` is `null`
- `uiState` is `'processing'`

#### Scenario: Submit disabled while loading status

- **WHEN** `statusLoading` is `true`
- **THEN** `isSubmitDisabled` is `true`

#### Scenario: Submit enabled when ready

- **WHEN** status is loaded, available, a file is selected, and `uiState` is `'idle'`
- **THEN** `isSubmitDisabled` is `false`

### Requirement: Demo page uses store instead of local useState

The demo Inertia page (`resources/js/pages/demo.tsx`) MUST consume the demo store for all parse-flow state listed above.

The page MUST NOT use `useState` for `selectedFile`, `statusAvailable`, `statusWarning`, `statusLoading`, `uiState`, `errorMessage`, or `result`.

The page MAY retain `useRef` for the file input element and `useEffect` only to invoke `fetchStatus` on mount.

#### Scenario: Component subscribes to store slices

- **WHEN** the demo page renders
- **THEN** UI state is read from the Zustand store
- **THEN** form handlers delegate to store actions
