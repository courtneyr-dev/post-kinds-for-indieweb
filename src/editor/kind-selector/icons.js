/**
 * Post Kinds for IndieWeb in Block Themes - Kind Icons
 *
 * SVG icons for each post kind type.
 *
 * @package
 * @since   1.0.0
 */

/**
 * WordPress dependencies
 */
import { SVG, Path, Circle } from '@wordpress/primitives';
import { applyFilters } from '@wordpress/hooks';

/**
 * Note icon - simple document/text
 *
 * @return {JSX.Element} SVG icon.
 */
export const NoteIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-1 14H6v-1.5h12V17zm0-3H6v-1.5h12V14zm0-3H6V9.5h12V11z" />
	</SVG>
);

/**
 * Article icon - document with title
 *
 * @return {JSX.Element} SVG icon.
 */
export const ArticleIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-1 4H6V5.5h12V7zm0 3H6V8.5h12V10zm0 3H6v-1.5h12V13zm-4 3H6v-1.5h8V16z" />
	</SVG>
);

/**
 * Reply icon - speech bubble with arrow
 *
 * @return {JSX.Element} SVG icon.
 */
export const ReplyIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" />
	</SVG>
);

/**
 * Like icon - heart
 *
 * @return {JSX.Element} SVG icon.
 */
export const LikeIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
	</SVG>
);

/**
 * Repost icon - refresh/share arrows
 *
 * @return {JSX.Element} SVG icon.
 */
export const RepostIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M7 7h10v3l4-4-4-4v3H5v6h2V7zm10 10H7v-3l-4 4 4 4v-3h12v-6h-2v4z" />
	</SVG>
);

/**
 * Bookmark icon - flag/bookmark
 *
 * @return {JSX.Element} SVG icon.
 */
export const BookmarkIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M17 3H7c-1.1 0-2 .9-2 2v16l7-3 7 3V5c0-1.1-.9-2-2-2z" />
	</SVG>
);

/**
 * RSVP icon - calendar with check
 *
 * @return {JSX.Element} SVG icon.
 */
export const RSVPIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zm-7.5-5l-3.5 3.5 1.5 1.5 2-2 4 4 1.5-1.5-5.5-5.5z" />
	</SVG>
);

/**
 * Checkin icon - location pin
 *
 * @return {JSX.Element} SVG icon.
 */
export const CheckinIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
	</SVG>
);

/**
 * Listen icon - headphones/music note
 *
 * @return {JSX.Element} SVG icon.
 */
export const ListenIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" />
	</SVG>
);

/**
 * Watch icon - film/movie
 *
 * @return {JSX.Element} SVG icon.
 */
export const WatchIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z" />
	</SVG>
);

/**
 * Read icon - open book
 *
 * @return {JSX.Element} SVG icon.
 */
export const ReadIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M21 5c-1.11-.35-2.33-.5-3.5-.5-1.95 0-4.05.4-5.5 1.5-1.45-1.1-3.55-1.5-5.5-1.5S2.45 4.9 1 6v14.65c0 .25.25.5.5.5.1 0 .15-.05.25-.05C3.1 20.45 5.05 20 6.5 20c1.95 0 4.05.4 5.5 1.5 1.35-.85 3.8-1.5 5.5-1.5 1.65 0 3.35.3 4.75 1.05.1.05.15.05.25.05.25 0 .5-.25.5-.5V6c-.6-.45-1.25-.75-2-1zm0 13.5c-1.1-.35-2.3-.5-3.5-.5-1.7 0-4.15.65-5.5 1.5V8c1.35-.85 3.8-1.5 5.5-1.5 1.2 0 2.4.15 3.5.5v11.5z" />
	</SVG>
);

/**
 * Event icon - calendar
 *
 * @return {JSX.Element} SVG icon.
 */
export const EventIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM9 10H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z" />
	</SVG>
);

/**
 * Photo icon - image/camera
 *
 * @return {JSX.Element} SVG icon.
 */
export const PhotoIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Circle cx="12" cy="12" r="3.2" />
		<Path d="M9 2L7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z" />
	</SVG>
);

/**
 * Video icon - play button
 *
 * @return {JSX.Element} SVG icon.
 */
export const VideoIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z" />
	</SVG>
);

/**
 * Review icon - star
 *
 * @return {JSX.Element} SVG icon.
 */
export const ReviewIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
	</SVG>
);

/**
 * Recipe icon - utensils
 *
 * @return {JSX.Element} SVG icon.
 */
export const RecipeIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4z" />
	</SVG>
);

/**
 * Favorite icon - star outline
 *
 * @return {JSX.Element} SVG icon.
 */
export const FavoriteIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z" />
	</SVG>
);

/**
 * Jam icon - music with fire/highlight
 *
 * @return {JSX.Element} SVG icon.
 */
export const JamIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 3v9.28c-.47-.17-.97-.28-1.5-.28C8.01 12 6 14.01 6 16.5S8.01 21 10.5 21c2.31 0 4.2-1.75 4.45-4H15V6h4V3h-7z" />
		<Path d="M19.48 12.35c-1.57-1.57-3.56-1.14-4.3-.24l.74.74c.42-.56 1.63-.86 2.78.29.58.58.58 1.53 0 2.12l-1.41 1.41 1.06 1.06 1.41-1.41c1.17-1.17 1.17-3.08-.28-3.97z" />
	</SVG>
);

/**
 * Wish icon - gift/present
 *
 * @return {JSX.Element} SVG icon.
 */
export const WishIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M20 6h-2.18c.11-.31.18-.65.18-1 0-1.66-1.34-3-3-3-1.05 0-1.96.54-2.5 1.35l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zM9 4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm11 15H4v-2h16v2zm0-5H4V8h5.08L7 10.83 8.62 12 11 8.76l1-1.36 1 1.36L15.38 12 17 10.83 14.92 8H20v6z" />
	</SVG>
);

/**
 * Mood icon - emoji face
 *
 * @return {JSX.Element} SVG icon.
 */
export const MoodIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z" />
	</SVG>
);

/**
 * Acquisition icon - shopping bag/box
 *
 * @return {JSX.Element} SVG icon.
 */
export const AcquisitionIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M18 6h-2c0-2.21-1.79-4-4-4S8 3.79 8 6H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6-2c1.1 0 2 .9 2 2h-4c0-1.1.9-2 2-2zm6 16H6V8h2v2c0 .55.45 1 1 1s1-.45 1-1V8h4v2c0 .55.45 1 1 1s1-.45 1-1V8h2v12z" />
	</SVG>
);

/**
 * Drink icon - coffee cup/beverage
 *
 * @return {JSX.Element} SVG icon.
 */
export const DrinkIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M20 3H4v10c0 2.21 1.79 4 4 4h6c2.21 0 4-1.79 4-4v-3h2c1.11 0 2-.89 2-2V5c0-1.11-.89-2-2-2zm0 5h-2V5h2v3zM4 19h16v2H4z" />
	</SVG>
);

/**
 * Eat icon - fork and knife/plate
 *
 * @return {JSX.Element} SVG icon.
 */
export const EatIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M8.1 13.34l2.83-2.83L3.91 3.5c-1.56 1.56-1.56 4.09 0 5.66l4.19 4.18zm6.78-1.81c1.53.71 3.68.21 5.27-1.38 1.91-1.91 2.28-4.65.81-6.12-1.46-1.46-4.2-1.1-6.12.81-1.59 1.59-2.09 3.74-1.38 5.27L3.7 19.87l1.41 1.41L12 14.41l6.88 6.88 1.41-1.41L13.41 13l1.47-1.47z" />
	</SVG>
);

/**
 * Play icon - game controller
 *
 * @return {JSX.Element} SVG icon.
 */
export const PlayIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M21.58 16.09l-1.09-7.66C20.21 6.46 18.52 5 16.53 5H7.47C5.48 5 3.79 6.46 3.51 8.43l-1.09 7.66C2.2 17.63 3.39 19 4.94 19h0c.68 0 1.32-.27 1.8-.75L9 16h6l2.25 2.25c.48.48 1.13.75 1.8.75h0C20.61 19 21.8 17.63 21.58 16.09zM11 11H9v2H8v-2H6v-1h2V8h1v2h2V11zm4-1c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1S15.55 10 15 10zm2 3c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1S17.55 13 17 13z" />
	</SVG>
);

/**
 * Audio icon - speaker with sound waves
 *
 * @return {JSX.Element} SVG icon.
 */
export const AudioIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z" />
	</SVG>
);

/**
 * Quote icon - quotation marks
 *
 * @return {JSX.Element} SVG icon.
 */
export const QuoteIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z" />
	</SVG>
);

/**
 * Tag icon - price tag with hole
 *
 * @return {JSX.Element} SVG icon.
 */
export const TagIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z" />
	</SVG>
);

/**
 * Weather icon - cloud
 *
 * @return {JSX.Element} SVG icon.
 */
export const WeatherIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z" />
	</SVG>
);

/**
 * Exercise icon - dumbbell
 *
 * @return {JSX.Element} SVG icon.
 */
export const ExerciseIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M20.57 14.86L22 13.43 20.57 12 17 15.57 8.43 7 12 3.43 10.57 2 9.14 3.43 7.71 2 5.57 4.14 4.14 2.71 2.71 4.14l1.43 1.43L2 7.71l1.43 1.43L2 10.57 3.43 12 7 8.43 15.57 17 12 20.57 13.43 22l1.43-1.43L16.29 22l2.14-2.14 1.43 1.43 1.43-1.43-1.43-1.43L22 16.29z" />
	</SVG>
);

/**
 * Trip icon - airplane
 *
 * @return {JSX.Element} SVG icon.
 */
export const TripIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z" />
	</SVG>
);

/**
 * Itinerary icon - ticket
 *
 * @return {JSX.Element} SVG icon.
 */
export const ItineraryIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M22 10V6c0-1.11-.9-2-2-2H4c-1.1 0-1.99.89-1.99 2v4c1.1 0 1.99.9 1.99 2s-.89 2-2 2v4c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2v-4c-1.1 0-2-.9-2-2s.9-2 2-2zm-9 7.5h-2v-2h2v2zm0-4.5h-2v-2h2v2zm0-4.5h-2v-2h2v2z" />
	</SVG>
);

/**
 * Follow icon - person with plus
 *
 * @return {JSX.Element} SVG icon.
 */
export const FollowIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
	</SVG>
);

/**
 * Issue icon - circle with center dot
 *
 * @return {JSX.Element} SVG icon.
 */
export const IssueIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z" />
		<Circle cx="12" cy="12" r="3" />
	</SVG>
);

/**
 * Question icon - question mark in circle
 *
 * @return {JSX.Element} SVG icon.
 */
export const QuestionIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z" />
	</SVG>
);

/**
 * Sleep icon - crescent moon
 *
 * @return {JSX.Element} SVG icon.
 */
export const SleepIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12.34 2.02C6.59 1.82 2 6.42 2 12c0 5.52 4.48 10 10 10 3.71 0 6.93-2.02 8.66-5.02-7.51-.25-12.09-8.43-8.32-14.96z" />
	</SVG>
);

/**
 * Craft icon - wrench/build tool
 *
 * @return {JSX.Element} SVG icon.
 */
export const CraftIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z" />
	</SVG>
);

/**
 * Map of kind slugs to icon components.
 *
 * @type {Object}
 */
export const kindIcons = {
	note: NoteIcon,
	article: ArticleIcon,
	reply: ReplyIcon,
	like: LikeIcon,
	repost: RepostIcon,
	bookmark: BookmarkIcon,
	rsvp: RSVPIcon,
	checkin: CheckinIcon,
	listen: ListenIcon,
	watch: WatchIcon,
	read: ReadIcon,
	event: EventIcon,
	photo: PhotoIcon,
	video: VideoIcon,
	review: ReviewIcon,
	recipe: RecipeIcon,
	favorite: FavoriteIcon,
	jam: JamIcon,
	wish: WishIcon,
	mood: MoodIcon,
	acquisition: AcquisitionIcon,
	drink: DrinkIcon,
	eat: EatIcon,
	play: PlayIcon,
	audio: AudioIcon,
	quote: QuoteIcon,
	tag: TagIcon,
	weather: WeatherIcon,
	exercise: ExerciseIcon,
	trip: TripIcon,
	itinerary: ItineraryIcon,
	follow: FollowIcon,
	issue: IssueIcon,
	question: QuestionIcon,
	sleep: SleepIcon,
	craft: CraftIcon,
	chicken: ChickenIcon,
	comics: ComicsIcon,
	collection: CollectionIcon,
	presentation: PresentationIcon,
};

/**
 * Chicken icon - a chicken (yes, really - indieweb.org/chicken).
 *
 * @return {JSX.Element} SVG icon.
 */
export const ChickenIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M16.5 3.5c.8 0 1.5.7 1.5 1.5 0 .4-.2.8-.4 1l1.9 1.4c.3.2.3.7 0 .9l-1.7 1.1c.1.5.2 1 .2 1.6 0 3.9-2.9 7-6.7 7h-.6l.3 2.1c0 .3-.2.5-.5.5h-1c-.2 0-.4-.2-.5-.4L8.7 18c-.4-.1-.8-.3-1.1-.5L6.2 19c-.2.2-.5.2-.7 0l-.7-.7c-.2-.2-.2-.5 0-.7l1.4-1.4C5.4 15.1 5 13.8 5 12.5 5 8.4 8 5 12 5c1 0 1.9.2 2.7.6.2-1.2 1-2.1 1.8-2.1Zm-.4 3.2a.7.7 0 1 0 0-1.4.7.7 0 0 0 0 1.4Z" />
	</SVG>
);

/**
 * Comics icon - POW! starburst.
 *
 * @return {JSX.Element} SVG icon.
 */
export const ComicsIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M12 1.5 14 7l4.2-3-1.6 5 5.6-.4-4.3 3.3 4.7 2.6-5.4.8 2.6 4.8-5-2.3L14 22.5l-2-4.7-4.2 3 1.6-5-5.6.4 4.3-3.3-4.7-2.6 5.4-.8L6.2 4.7l5 2.3Zm-1.7 8.2v4.6h1.2v-1.5h.6c1 0 1.8-.7 1.8-1.6 0-.9-.8-1.5-1.8-1.5h-1.8Zm1.2 1h.5c.4 0 .6.2.6.5s-.2.6-.6.6h-.5v-1.1Z" />
	</SVG>
);

/**
 * Collection icon - stacked items.
 *
 * @return {JSX.Element} SVG icon.
 */
export const CollectionIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M4 8h16c.6 0 1 .4 1 1v10c0 1.1-.9 2-2 2H5c-1.1 0-2-.9-2-2V9c0-.6.4-1 1-1Zm1 2v9h14v-9H5ZM5 5h14v1.5H5V5Zm2-3h10v1.5H7V2Z" />
	</SVG>
);

/**
 * Presentation icon - screen and microphone.
 *
 * @return {JSX.Element} SVG icon.
 */
export const PresentationIcon = () => (
	<SVG viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
		<Path d="M3 4h18c.6 0 1 .4 1 1v10c0 .6-.4 1-1 1h-7.6l1.4 3H16a1 1 0 1 1 0 2H8a1 1 0 1 1 0-2h1.2l1.4-3H3c-.6 0-1-.4-1-1V5c0-.6.4-1 1-1Zm1 2v8h16V6H4Zm12.6 1c.9 0 1.6.7 1.6 1.6v1.8a1.6 1.6 0 0 1-3.2 0V8.6c0-.9.7-1.6 1.6-1.6Zm-2.8 3.5a2.8 2.8 0 0 0 5.6 0h1.2a4 4 0 0 1-3.4 3.9v1.1h-1.2v-1.1a4 4 0 0 1-3.4-3.9h1.2Z" />
	</SVG>
);

/**
 * Resolve the icon component for a kind slug.
 *
 * Runs the `postKindsIndieweb.kindIcons` filter so sites can register
 * icons for custom kind terms (or override built-ins):
 *
 *     wp.hooks.addFilter( 'postKindsIndieweb.kindIcons', 'my-site', ( icons ) => ( {
 *         ...icons,
 *         chicken: () => wp.element.createElement( 'span', { 'aria-hidden': true }, '\u{1F414}' ),
 *     } ) );
 *
 * Unknown slugs fall back to the note icon.
 *
 * @param {string} slug Kind slug.
 * @return {Function} Icon component.
 */
export function getKindIcon( slug ) {
	const icons = applyFilters( 'postKindsIndieweb.kindIcons', {
		...kindIcons,
	} );
	return icons[ slug ] || icons.note || kindIcons.note;
}

export default kindIcons;
